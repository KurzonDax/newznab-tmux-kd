<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Models\Settings;
use App\Services\CollectionsCleaningService;
use App\Services\ObfuscationRecovery\RecoveryCollectionOwnership;
use App\Services\Releases\CollectionQuietPredicate;
use App\Support\Data\ProcessReleasesSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use UnexpectedValueException;

/** Cheap pre-mutation admission; this boundary never opens a provider connection. */
final class CollectionAdmission
{
    public const int HOLD_SECONDS = 7200;

    public function __construct(private readonly CollectionsCleaningService $cleaning) {}

    /**
     * Lock the bounded discovery population before screening and ordinary mutation.
     * The caller keeps these locks through its mutation; ingestion uses the same rows.
     *
     * @param  list<int>  $ids
     */
    public function lockAndScreen(array $ids, ?int $quietHours = null): bool
    {
        if (! Schema::hasTable('reconciliation_admissions')) {
            return true;
        }
        $sources = DB::table('collections')->whereIn('id', $ids)->orderBy('id')->get();
        $bound = max(500, count($ids) * 257);
        $population = DB::table('collections')->where(function ($query) use ($ids, $sources): void {
            $query->whereIn('id', $ids);
            foreach ($sources as $source) {
                $query->orWhere(static function ($window) use ($source): void {
                    $window->where('groups_id', $source->groups_id)->where('declaredfiles', $source->declaredfiles)
                        ->where('fromname', $source->fromname)->whereIn('filecheck', [0, 1, 2, 3, 10, 15, 16])
                        ->whereBetween('date', [gmdate('Y-m-d H:i:s', strtotime($source->date) - 3600), gmdate('Y-m-d H:i:s', strtotime($source->date) + 3600)]);
                });
            }
        })->orderBy('id')->limit($bound + 1)->lockForUpdate()->get();
        if ($population->count() > $bound) {
            return false;
        }
        foreach ($sources as $source) {
            $current = $population->firstWhere('id', $source->id);
            if ($current !== null && [$current->groups_id, $current->declaredfiles, $current->fromname, $current->date]
                !== [$source->groups_id, $source->declaredfiles, $source->fromname, $source->date]) {
                return false;
            }
        }

        return $this->screen($ids, $quietHours, $population);
    }

    private function nearby(object $source): Builder
    {
        return DB::table('collections')->where('groups_id', $source->groups_id)
            ->where('declaredfiles', $source->declaredfiles)->where('fromname', $source->fromname)
            ->whereIn('filecheck', [0, 1, 2, 3, 10, 15, 16])
            ->whereBetween('date', [gmdate('Y-m-d H:i:s', strtotime($source->date) - 3600), gmdate('Y-m-d H:i:s', strtotime($source->date) + 3600)])
            ->orderBy('id')->limit(257);
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
                if (isset($seen[$source->id])) {
                    continue;
                }
                $nearby = $lockedPopulation === null ? $this->nearby($source)->get() : $lockedPopulation->filter(
                    static fn ($candidate): bool => (int) $candidate->groups_id === (int) $source->groups_id
                        && (int) $candidate->declaredfiles === (int) $source->declaredfiles && $candidate->fromname === $source->fromname
                        && abs(strtotime($candidate->date) - strtotime($source->date)) <= 3600
                        && in_array((int) $candidate->filecheck, [0, 1, 2, 3, 10, 15, 16], true));
                if ($nearby->count() > 256) {
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
        $collections = DB::table('collections as c')->join('usenet_groups as g', 'g.id', '=', 'c.groups_id')
            ->whereIn('c.id', $ids)->whereIn('c.filecheck', [0, 1, 2, 3, 10, 15, 16])
            ->whereRaw($quiet['sql'], $quiet['bindings'])
            ->tap(static fn ($query) => RecoveryCollectionOwnership::exclude($query, 'c.id'))
            ->tap(static fn ($query) => CollectionOwnership::excludeArtifactSources($query, 'c.id'))
            ->orderBy('c.id')->when($locked, static fn ($query) => $query->lockForUpdate())->get(['c.*', 'g.name as group_name'])->keyBy('id');
        $partAlias = DB::getTablePrefix().'p';
        $parts = DB::table('parts as p')->join('binaries as selected', 'selected.id', '=', 'p.binaries_id')
            ->whereIn('selected.collections_id', $ids)->groupBy('p.binaries_id')
            ->when($locked, static fn ($query) => $query->lockForUpdate())->selectRaw("{$partAlias}.binaries_id, COUNT(*) AS held, MIN({$partAlias}.partnumber) AS first_part, MAX({$partAlias}.partnumber) AS last_part, SUM({$partAlias}.size) AS bytes");
        $binaries = DB::table('binaries as b')->leftJoinSub($parts, 'p', 'p.binaries_id', '=', 'b.id')
            ->leftJoin('parts as first', static fn ($join) => $join->on('first.binaries_id', '=', 'b.id')->where('first.partnumber', 1))
            ->whereIn('b.collections_id', $ids)->orderBy('b.id')->limit(1025)->when($locked, static fn ($query) => $query->lockForUpdate())
            ->get(['b.id', 'b.collections_id', 'b.name', 'b.totalparts', 'p.held', 'p.first_part', 'p.last_part', 'p.bytes', 'first.messageid']);
        if ($binaries->count() > 1024) {
            return null;
        }
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
                    $family = $this->cleaning->collectionsCleaner($subject, $collection->group_name)['name'];
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
