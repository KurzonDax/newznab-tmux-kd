<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Models\Settings;
use App\Services\CollectionsCleaningService;
use App\Services\ObfuscationRecovery\RecoveryCollectionOwnership;
use App\Services\Releases\CollectionQuietPredicate;
use App\Support\Data\ProcessReleasesSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use UnexpectedValueException;

/** Cheap pre-mutation admission; this boundary never opens a provider connection. */
final class CollectionAdmission
{
    public const int HOLD_SECONDS = 7200;

    public const int MUTATION_BATCH_SIZE = 8;

    public function __construct(private readonly CollectionsCleaningService $cleaning) {}

    /**
     * Lock the bounded discovery population before screening and ordinary mutation.
     * The caller keeps these locks through its mutation; ingestion uses the same rows.
     *
     * @param  list<int>  $ids
     */
    public function lockAndScreen(array $ids, ?int $quietHours = null): bool
    {
        if (count(array_unique($ids)) > self::MUTATION_BATCH_SIZE) {
            return false;
        }
        if (! Schema::hasTable('reconciliation_admissions')) {
            return true;
        }
        $queries = new PopulationQuery;
        $sources = $queries->lockIds($ids);
        if ($sources->count() !== count(array_unique($ids))) {
            return false;
        }
        $windows = [];
        $sourceWindows = [];
        foreach ($sources as $source) {
            $window = $queries->sourceWindow($source);
            if ($window === null) {
                continue;
            }
            $key = json_encode($window, JSON_THROW_ON_ERROR);
            $windows[$key] = $window;
            $sourceWindows[(int) $source->id] = $key;
        }
        ksort($windows);
        $populations = [];
        foreach ($windows as $key => $window) {
            $populations[$key] = $queries->lockWindow($window);
        }
        foreach ($sourceWindows as $id => $key) {
            $population = $populations[$key];
            if (! $population['complete']) {
                continue;
            }
            $rows = $population['rows']->concat($sources->where('id', $id))->unique('id')->sortBy('id')->values();
            if (! $this->screen([$id], $quietHours, $rows)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<int>  $ids
     * @param  Collection<int, \stdClass>|null  $lockedPopulation
     */
    public function screen(array $ids, ?int $quietHours = null, ?Collection $lockedPopulation = null): bool
    {
        if (! Schema::hasTable('reconciliation_admissions')) {
            return true;
        }
        $quietHours ??= ProcessReleasesSettings::forDatabase(['delaytime' => Settings::settingValue('delaytime')])->collectionDelayTime;
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $page) {
            $seen = [];
            foreach (($lockedPopulation?->whereIn('id', $page) ?? DB::table('collections')->whereIn('id', $page)->orderBy('id')->get()) as $source) {
                if ($source->date === null || isset($seen[$source->id])) {
                    continue;
                }
                $queries = new PopulationQuery;
                $window = $queries->sourceWindow($source);
                if ($window === null) {
                    continue;
                }
                $population = $lockedPopulation === null ? $queries->readWindow($window) : null;
                if ($population !== null && ! $population['complete']) {
                    continue;
                }
                $nearby = $population['rows'] ?? $lockedPopulation->filter(
                    static fn ($candidate): bool => (int) $candidate->groups_id === (int) $source->groups_id
                        && (int) $candidate->declaredfiles === (int) $source->declaredfiles && $candidate->fromname === $source->fromname
                        && $candidate->date !== null && $candidate->date >= $window['from'] && $candidate->date <= $window['until']
                        && in_array((int) $candidate->filecheck, PopulationQuery::STATES, true));
                if ($nearby->count() > PopulationQuery::LIMIT) {
                    continue;
                }
                if ($nearby->count() < 2) {
                    continue;
                }
                $populationIds = $nearby->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                $snapshots = $this->snapshots($populationIds, $quietHours, $lockedPopulation !== null);
                if ($snapshots === null) {
                    continue;
                }
                $seen[$source->id] = true;
                foreach ($this->families($snapshots) as $group) {
                    if (! isset($group[$source->id])) {
                        continue;
                    }
                    if (! $this->persist($group, $quietHours)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /** @param list<int> $ids
     * @return list<int>|null
     */
    public function eligibleIds(array $ids, int $quietHours): ?array
    {
        $snapshots = $this->snapshots($ids, $quietHours);

        return $snapshots === null ? null : array_keys($snapshots);
    }

    public function decisionId(int $baseSourceId, string $baseArticle): string
    {
        $id = hash('sha256', 'pending:'.$baseArticle);
        if (! Schema::hasTable('reconciliation_decisions')) {
            return $id;
        }
        DB::table('reconciliation_decisions')->insertOrIgnore(['base_collection_id' => $baseSourceId, 'decision_id' => $id]);

        return DB::table('reconciliation_decisions')->where('base_collection_id', $baseSourceId)->value('decision_id');
    }

    public function admitProven(PostingDecision $decision, int $quietHours): bool
    {
        if (! Schema::hasTable('reconciliation_admissions')) {
            return true;
        }
        $ids = array_map('intval', $decision->sources());
        $sources = $this->snapshots($ids, $quietHours);
        if ($sources === null || count($sources) < 2 || count($sources) !== count($ids)) {
            return false;
        }
        $stored = [];
        foreach ($sources as $source) {
            foreach ($source['files'] as $file) {
                $stored[] = $file['id'];
            }
        }
        $accepted = array_map(static fn (PostingFile $file): int => (int) $file->fileId, $decision->accepted);
        sort($stored);
        sort($accepted);

        return $stored === $accepted && $this->persist($sources, $quietHours)
            && DB::table('reconciliation_admissions')->whereIn('collection_id', $ids)->where('state', 'admitted')
                ->where('expires_at', '>', now())->count() === count($ids);
    }

    /** @param list<int> $ids */
    public function expired(array $ids): bool
    {
        return Schema::hasTable('reconciliation_admissions') && DB::table('reconciliation_admissions')
            ->whereIn('collection_id', $ids)->where('expires_at', '<=', now())->exists();
    }

    /** @param list<int> $ids */
    public function disprove(array $ids, ?string $decisionId = null): void
    {
        if (Schema::hasTable('reconciliation_admissions')) {
            $decisions = DB::table('reconciliation_admissions')->whereIn('collection_id', $ids)
                ->when($decisionId !== null, static fn ($query) => $query->where('decision_id', $decisionId))->pluck('decision_id');
            DB::table('reconciliation_admissions')->whereIn('decision_id', $decisions)
                ->where('state', 'admitted')->update(['state' => 'disproved']);
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>|null
     */
    private function snapshots(array $ids, int $quietHours, bool $locked = false): ?array
    {
        $quiet = CollectionQuietPredicate::build($quietHours, DB::getTablePrefix().'c');
        $collections = DB::table('collections as c')
            ->whereIn('c.id', $ids)->whereIn('c.filecheck', PopulationQuery::STATES)
            ->whereRaw($quiet['sql'], $quiet['bindings'])
            ->tap(static fn ($query) => RecoveryCollectionOwnership::exclude($query, 'c.id'))
            ->tap(static fn ($query) => CollectionOwnership::excludeArtifactSources($query, 'c.id'))
            ->orderBy('c.id')->when($locked, static fn ($query) => $query->lockForUpdate())->get(['c.*'])->keyBy('id');
        $groupNames = DB::table('usenet_groups')->whereIn('id', $collections->pluck('groups_id'))->pluck('name', 'id');
        $collections = $collections->filter(static fn ($collection): bool => $groupNames->has($collection->groups_id));
        $binaryIds = [];
        sort($ids);
        foreach ($ids as $id) {
            $selected = DB::table('binaries')->where('collections_id', $id)->limit(1025 - count($binaryIds))
                ->when($locked, static fn ($query) => $query->lockForUpdate())->pluck('id')->all();
            $binaryIds = [...$binaryIds, ...$selected];
            if (count($binaryIds) > 1024) {
                return null;
            }
        }
        $partAlias = DB::getTablePrefix().'p';
        $parts = DB::table('parts as p')->whereIn('p.binaries_id', $binaryIds)->groupBy('p.binaries_id')
            ->when(DB::getDriverName() !== 'sqlite', static fn ($query) => $query->forceIndex('PRIMARY'))
            ->when($locked, static fn ($query) => $query->lockForUpdate())->selectRaw("{$partAlias}.binaries_id, COUNT(*) AS held, MIN({$partAlias}.partnumber) AS first_part, MAX({$partAlias}.partnumber) AS last_part, SUM({$partAlias}.size) AS bytes");
        $binaries = DB::table('binaries as b')->leftJoinSub($parts, 'p', 'p.binaries_id', '=', 'b.id')
            ->leftJoin('parts as first', static fn ($join) => $join->on('first.binaries_id', '=', 'b.id')->where('first.partnumber', 1))
            ->whereIn('b.id', $binaryIds)->orderBy('b.id')->when($locked, static fn ($query) => $query->lockForUpdate())
            ->get(['b.id', 'b.collections_id', 'b.name', 'b.totalparts', 'p.held', 'p.first_part', 'p.last_part', 'p.bytes', 'first.messageid']);
        $snapshots = [];
        foreach ($collections as $id => $collection) {
            $files = [];
            $families = [];
            try {
                foreach ($binaries->where('collections_id', $id) as $binary) {
                    $parsed = PostingFile::subject($binary->name);
                    if ((int) $binary->totalparts < 1 || (int) $binary->held !== (int) $binary->totalparts
                        || (int) $binary->first_part !== 1 || (int) $binary->last_part !== (int) $binary->totalparts
                        || $parsed['total'] !== (int) $collection->declaredfiles || isset($files[$parsed['ordinal']])) {
                        throw new UnexpectedValueException('ineligible_inventory');
                    }
                    $subject = preg_replace('/ \(\d+\/\d+\)$/D', '', $binary->name) ?? '';
                    $family = $this->cleaning->collectionsCleaner($subject, (string) $groupNames[$collection->groups_id])['name'];
                    $families[$family] = true;
                    $files[$parsed['ordinal']] = $parsed + ['article' => '<'.trim((string) $binary->messageid, '<>').'>',
                        'parts' => (int) $binary->totalparts, 'bytes' => (int) $binary->bytes, 'id' => (int) $binary->id];
                }
                if ($files === [] || count($files) >= (int) $collection->declaredfiles || count($families) !== 1) {
                    continue;
                }
            } catch (UnexpectedValueException) {
                continue;
            }
            $snapshot = ['id' => (int) $id, 'group' => (int) $collection->groups_id, 'poster' => (string) $collection->fromname,
                'date' => (int) strtotime($collection->date), 'total' => (int) $collection->declaredfiles,
                'family' => strtr((string) array_key_first($families), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'),
                'files' => $files, 'head' => $collection->last_seen_head_postdate ?? null,
                'tail' => $collection->last_seen_tail_postdate ?? null, 'seen' => $collection->last_seen_at ?? null];
            $snapshot['revision'] = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            $snapshots[(int) $id] = $snapshot;
        }

        return $snapshots;
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return list<array<int, array<string, mixed>>>
     */
    private function families(array $snapshots): array
    {
        $groups = [];
        foreach ($snapshots as $baseSource) {
            foreach ($baseSource['files'] as $file) {
                if (! $this->basePar2($file['filename'])) {
                    continue;
                }
                $members = array_filter($snapshots, static fn (array $source): bool => $source['group'] === $baseSource['group']
                    && $source['poster'] === $baseSource['poster'] && $source['total'] === $baseSource['total']
                    && $source['family'] === $baseSource['family'] && abs($source['date'] - $baseSource['date']) <= 1800);
                $ordinals = [];
                $baseCount = 0;
                $payload = false;
                foreach ($members as $member) {
                    foreach ($member['files'] as $ordinal => $candidate) {
                        if (isset($ordinals[$ordinal])) {
                            continue 4;
                        }
                        $ordinals[$ordinal] = true;
                        $baseCount += (int) $this->basePar2($candidate['filename']);
                        $payload = $payload || ($member['id'] !== $baseSource['id'] && preg_match('/\.par2$/iD', $candidate['filename']) !== 1);
                    }
                }
                if (count($members) >= 2 && $baseCount === 1 && $payload) {
                    $groups[] = $members;
                }
            }
        }

        return array_values(array_filter($groups, static function (array $group, int $index) use ($groups): bool {
            foreach ($groups as $otherIndex => $other) {
                if ($otherIndex !== $index && array_intersect_key($group, $other) !== []) {
                    return false;
                }
            }

            return true;
        }, ARRAY_FILTER_USE_BOTH));
    }

    private function basePar2(string $name): bool
    {
        return preg_match('/\.par2$/iD', $name) === 1 && preg_match('/\.vol\d+\+\d+\.par2$/iD', $name) !== 1;
    }

    /** @param array<int, array<string, mixed>> $group */
    private function persist(array $group, int $quietHours): bool
    {
        $ids = array_keys($group);
        sort($ids);

        return DB::transaction(function () use ($group, $ids, $quietHours): bool {
            DB::table('collections')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($this->snapshots($ids, $quietHours, true) !== $group) {
                return false;
            }
            $existing = DB::table('reconciliation_admissions')->whereIn('collection_id', $ids)->orderBy('collection_id')->lockForUpdate()->get();
            if ($existing->contains(static fn ($row): bool => ! in_array($row->state, ['admitted', 'invalidated'], true) || strtotime($row->expires_at) <= now()->timestamp)) {
                DB::table('reconciliation_admissions')->whereIn('collection_id', $ids)->where('expires_at', '<=', now())->update(['state' => 'expired']);

                return true;
            }
            $decisions = $existing->pluck('decision_id')->unique();
            $baseArticle = '';
            $baseSourceId = 0;
            foreach ($group as $source) {
                foreach ($source['files'] as $file) {
                    if ($this->basePar2($file['filename'])) {
                        $baseArticle = $file['article'];
                        $baseSourceId = $source['id'];
                    }
                }
            }
            $decision = $decisions->first() ?? $this->decisionId($baseSourceId, $baseArticle);
            $first = $existing->min('admitted_at') ?? now()->toDateTimeString();
            $deadline = $existing->min('expires_at') ?? now()->addSeconds(self::HOLD_SECONDS)->toDateTimeString();
            DB::table('reconciliation_admissions')->whereIn('collection_id', $ids)->update([
                'admitted_at' => $first, 'expires_at' => $deadline, 'decision_id' => $decision,
            ]);
            foreach ($group as $source) {
                DB::table('reconciliation_admissions')->where('collection_id', $source['id'])->where('state', 'invalidated')
                    ->update(['state' => 'admitted', 'revision' => $source['revision']]);
                DB::table('reconciliation_admissions')->insertOrIgnore(['collection_id' => $source['id'],
                    'decision_id' => $decision, 'revision' => $source['revision'], 'admitted_at' => $first, 'expires_at' => $deadline, 'state' => 'admitted']);
            }

            return true;
        }, 3);
    }
}
