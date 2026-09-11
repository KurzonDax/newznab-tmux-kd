<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\CollectionDeletionReason;
use App\Services\CollectionReconciliation\CollectionOwnership;
use App\Services\ObfuscationRecovery\RecoveryCollectionOwnership;
use App\Support\DatabaseClock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Maintenance eligibility is applied both to a bounded discovery page and under source locks. */
final readonly class CollectionDeletionSelection
{
    /** @param array<int, int|null>|null $expectedLinks */
    public function __construct(public CollectionDeletionReason $reason, public int $hours = 72, public ?array $expectedLinks = null, public ?int $groupId = null) {}

    /**
     * @param  list<int>  $ids
     * @param  array<int, int|null>  $expectedLinks
     */
    public function query(array $ids, bool $currentRead = false, array $expectedLinks = []): Builder
    {
        $query = DB::table('collections')->whereIn('id', $ids);
        if ($this->groupId !== null) {
            $query->where('groups_id', $this->groupId);
        }
        RecoveryCollectionOwnership::exclude($query, populationIds: $ids, currentRead: $currentRead);
        CollectionOwnership::exclude($query, populationIds: $ids, currentRead: $currentRead);
        if ($this->reason === CollectionDeletionReason::Orphan) {
            $query->whereNotExists(static fn ($binaries) => $binaries->selectRaw('1')->from('binaries')
                ->whereIn('collections_id', $ids)->whereColumn('binaries.collections_id', 'collections.id')
                ->when($currentRead, static fn ($query) => $query->lockForUpdate()));
        } elseif ($this->reason === CollectionDeletionReason::Retention) {
            $cutoff = DatabaseClock::cutoff(now()->subHours($this->hours));
            $query->whereRaw('dateadded < '.$cutoff['sql'], $cutoff['bindings'])->whereNotIn('filecheck', [0, 1, 10, 15, 16]);
        } elseif ($this->reason === CollectionDeletionReason::Stuck) {
            $quiet = CollectionQuietPredicate::build($this->hours, DB::getTablePrefix().'collections', 'added', $currentRead);
            $query->whereIn('filecheck', [0, 1, 10, 15, 16])->whereRaw($quiet['sql'], $quiet['bindings']);
        } else {
            $releaseIds = $currentRead ? array_values($expectedLinks)
                : DB::table('collections')->whereIn('id', $ids)->pluck('releases_id')->all();
            $query->whereExists(static fn ($release) => $release->selectRaw('1')->from('releases')
                ->whereIn('id', $releaseIds)->whereColumn('releases.id', 'collections.releases_id')->where('nzbstatus', 1)
                ->when($currentRead, static fn ($query) => $query->lockForUpdate()));
            if ($currentRead) {
                $query->where(static function (Builder $links) use ($expectedLinks): void {
                    $links->whereRaw('1 = 0');
                    foreach ($expectedLinks as $id => $releaseId) {
                        $links->orWhere(static fn ($pair) => $pair->where('id', $id)->where('releases_id', $releaseId));
                    }
                });
            }
        }

        return $query->orderBy('id')->when($currentRead, static fn ($query) => $query->lockForUpdate());
    }
}
