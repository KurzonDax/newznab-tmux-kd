<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\CollectionFileCheckStatus;
use App\Services\Nzb\Par2Inventory;
use App\Services\ObfuscationRecovery\RecoveryCollectionOwnership;
use App\Support\Data\ProcessReleasesSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reopens only operator-reviewed, unchanged orphans into normal collection measurement.
 */
final class OrphanedCollectionRecovery
{
    public const int MAX_ENTRIES = 100;

    public const int MAX_BINARIES = 10000;

    public const int MAX_PARTS = 100000;

    /** @return list<array<string, mixed>> */
    public function discover(int $limit, int $afterId = 0): array
    {
        return DB::table('collections')->tap(static fn ($query) => RecoveryCollectionOwnership::exclude($query))
            ->where('id', '>', $afterId)
            ->where('filecheck', CollectionFileCheckStatus::Inserted->value)
            ->where('releases_id', '>', 0)
            ->whereNotExists(static function (Builder $query): void {
                $query->selectRaw('1')->from('releases')->whereColumn('releases.id', 'collections.releases_id');
            })
            ->orderBy('id')->limit(max(1, min(self::MAX_ENTRIES, $limit)))
            ->get()->map(fn (object $collection): array => $this->snapshot($collection))->all();
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public function apply(array $entry): array
    {
        $id = filter_var($entry['collection_id'] ?? null, FILTER_VALIDATE_INT);
        $oldId = filter_var($entry['former_release_id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1 || $oldId === false || $oldId < 1
            || ! is_string($entry['hash'] ?? null) || preg_match('/^[a-f0-9]{40}$/D', $entry['hash']) !== 1
            || ($entry['selected'] ?? false) !== true || ($entry['reviewed_unintentional_deletion'] ?? false) !== true) {
            return ['collection_id' => $id, 'outcome' => 'not_selected_or_reviewed'];
        }

        return DB::transaction(function () use ($entry, $id, $oldId): array {
            $outcome = ['collection_id' => $id, 'old_release_id' => $oldId, 'new_release_id' => null];
            $parent = DB::table('releases')->where('id', $oldId)->lockForUpdate()->first();
            $collection = DB::table('collections')->where('id', $id)->lockForUpdate()->first();
            if ($collection === null) {
                $replacement = DB::table('releases')->where('collectionhash', hex2bin($entry['hash']))->value('id');

                if ($replacement !== null && (int) $replacement !== $oldId) {
                    return [...$outcome, 'new_release_id' => (int) $replacement, 'outcome' => 'formed'];
                }

                return [...$outcome, 'outcome' => 'collection_missing'];
            }
            if (RecoveryCollectionOwnership::protects((int) $collection->id)) {
                return [...$outcome, 'outcome' => 'recovery_owned'];
            }
            if (bin2hex((string) $collection->collectionhash) !== ($entry['hash'] ?? null)) {
                return [...$outcome, 'outcome' => 'identity_changed'];
            }
            if ($collection->releases_id === null && (int) $collection->filecheck === CollectionFileCheckStatus::CompleteCollection->value) {
                return [...$outcome, 'outcome' => 'pending_normal_formation'];
            }
            if ($parent !== null || (int) $collection->releases_id !== $oldId) {
                return [...$outcome, 'outcome' => 'parent_or_link_changed'];
            }
            $group = DB::table('usenet_groups')->where('id', $collection->groups_id)->lockForUpdate()->first();
            if ($group === null) {
                return [...$outcome, 'outcome' => 'group_missing'];
            }
            $snapshot = $this->snapshot($collection, true);
            if (! $snapshot['eligible'] || ! hash_equals($snapshot['fingerprint'], (string) ($entry['fingerprint'] ?? ''))) {
                return [...$outcome, 'outcome' => 'replan', 'reasons' => $snapshot['reasons']];
            }
            $changed = DB::table('collections')->where('id', $id)
                ->where('releases_id', $oldId)
                ->where('filecheck', CollectionFileCheckStatus::Inserted->value)
                ->update(['releases_id' => null, 'filecheck' => CollectionFileCheckStatus::CompleteCollection->value]);
            if ($changed !== 1) {
                throw new RuntimeException('Orphan recovery did not update its selected collection.');
            }

            return [...$outcome, 'outcome' => 'pending_normal_formation'];
        }, 3);
    }

    /** @return array<string, mixed> */
    private function snapshot(object $collection, bool $lock = false): array
    {
        $reasons = [];
        $binariesQuery = DB::table('binaries')->where('collections_id', $collection->id)->orderBy('id')->limit(self::MAX_BINARIES + 1);
        if ($lock) {
            $binariesQuery->lockForUpdate();
        }
        $binaries = $binariesQuery->get();
        $group = DB::table('usenet_groups')->where('id', $collection->groups_id)->first();
        $fingerprint = hash_init('sha256');
        hash_update($fingerprint, serialize([$collection, $group]));
        $bytes = 0;
        $partsCount = 0;
        $payload = false;
        if ($binaries->isEmpty() || $binaries->count() > self::MAX_BINARIES) {
            $reasons[] = 'absent_or_excessive_binaries';
        } else {
            foreach ($binaries as $binary) {
                hash_update($fingerprint, serialize($binary));
                $partsQuery = DB::table('parts')->where('binaries_id', $binary->id)
                    ->orderBy('partnumber')->limit(self::MAX_PARTS - $partsCount + 1);
                if ($lock) {
                    $partsQuery->lockForUpdate();
                }
                $parts = $partsQuery->get();
                $partsCount += $parts->count();
                if ($partsCount > self::MAX_PARTS) {
                    $reasons[] = 'part_limit';
                    break;
                }
                if ((int) $binary->totalparts < 1 || $parts->count() !== (int) $binary->totalparts
                    || $parts->unique('partnumber')->count() !== $parts->count()
                    || $parts->min('partnumber') != 1 || $parts->max('partnumber') != (int) $binary->totalparts) {
                    $reasons[] = 'missing_parts';
                }
                foreach ($parts as $part) {
                    hash_update($fingerprint, serialize($part));
                    $bytes += (int) $part->size;
                    if ((int) $part->size <= 0 || trim((string) $part->messageid) === '') {
                        $reasons[] = 'invalid_parts';
                    }
                }
                $name = Par2Inventory::filename((string) $binary->name);
                if ($name === null) {
                    $reasons[] = 'ambiguous_filename';
                } elseif (preg_match('/\.(?:rar|r\d\d|7z|zip|mkv|mp4|avi|mp3|flac|m4a|wav|iso)$/iD', $name)) {
                    $payload = true;
                }
            }
        }
        if (! $payload) {
            $reasons[] = 'no_verified_payload';
        }
        if ($group === null) {
            $reasons[] = 'group_missing';
        }
        if ((int) $collection->filecheck !== CollectionFileCheckStatus::Inserted->value) {
            $reasons[] = 'nonterminal_state';
        }
        if ((int) $collection->releases_id < 1 || DB::table('releases')->where('id', $collection->releases_id)->exists()) {
            $reasons[] = 'not_orphaned';
        }
        $quiet = CollectionQuietPredicate::build(ProcessReleasesSettings::forDatabase(DB::table('settings')->where('name', 'delaytime')->pluck('value', 'name')->all())->collectionDelayTime);
        $isQuiet = DB::table('collections')->where('id', $collection->id)->whereRaw($quiet['sql'], $quiet['bindings'])->exists();
        if (! $isQuiet) {
            $reasons[] = 'frontier_or_quiet_pending';
        }
        $duplicates = DB::table('releases')->where(function (Builder $query) use ($collection): void {
            $query->where('collectionhash', $collection->collectionhash)
                ->orWhere('name', $collection->subject)->orWhere('searchname', $collection->subject);
        })->orderBy('id')->limit(11)->pluck('id')->all();
        if ($duplicates !== []) {
            $reasons[] = 'duplicate_conflict';
        }

        return [
            'collection_id' => (int) $collection->id,
            'former_release_id' => (int) $collection->releases_id,
            'hash' => bin2hex((string) $collection->collectionhash),
            'group_id' => (int) $collection->groups_id,
            'declared_files' => (int) $collection->declaredfiles,
            'files' => $binaries->count(), 'parts' => $partsCount, 'bytes' => $bytes,
            'payload' => $payload, 'quiet' => $isQuiet,
            'duplicate_candidates' => $duplicates,
            'fingerprint' => hash_final($fingerprint),
            'eligible' => $reasons === [], 'reasons' => array_values(array_unique($reasons)),
            'historical_cause' => 'unknown',
            'selected' => false, 'reviewed_unintentional_deletion' => false,
        ];
    }
}
