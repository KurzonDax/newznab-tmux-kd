<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Services\Releases\CollectionQuietPredicate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CollectionClaims
{
    public function __construct(private readonly PendingInventory $inventory) {}

    /** @param list<int> $ids */
    public function claim(array $ids, string $owner, string $revision, int $quietHours): ?int
    {
        sort($ids);

        return DB::transaction(function () use ($ids, $owner, $revision, $quietHours): ?int {
            $collections = DB::table('collections')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $quiet = CollectionQuietPredicate::build($quietHours, DB::getTablePrefix().'collections');
            if ($collections->count() !== count($ids) || DB::table('collections')->whereIn('id', $ids)->whereRaw($quiet['sql'], $quiet['bindings'])->count() !== count($ids)
                || $collections->contains(static fn ($row): bool => (int) $row->filecheck === 4 || (int) $row->filecheck === 5)) {
                return null;
            }
            foreach ($ids as $id) {
                DB::table('reconciliation_claims')->insertOrIgnore(['collection_id' => $id,
                    'deadline' => now()->addSeconds((int) config('collection-reconciliation.decision_seconds'))]);
            }
            $claims = DB::table('reconciliation_claims')->whereIn('collection_id', $ids)->orderBy('collection_id')->lockForUpdate()->get();
            foreach ($claims as $claim) {
                if ($claim->release_id !== null || strtotime($claim->deadline) <= now()->timestamp
                    || ($claim->owner !== null && strtotime($claim->lease_until ?? '') > now()->timestamp)
                    || ($claim->retry_at !== null && strtotime($claim->retry_at) > now()->timestamp)
                    || ((int) $claim->attempts >= 3)
                    || (! in_array($claim->reason, ['pending', 'retry', 'budget_exhausted'], true) && $claim->revision === $revision)) {
                    return null;
                }
            }
            if (PendingInventory::digest($this->inventory->load($ids)) !== $revision) {
                return null;
            }
            DB::table('reconciliation_claims')->whereIn('collection_id', $ids)->update([
                'owner' => $owner, 'revision' => $revision, 'reason' => 'pending',
                'lease_until' => now()->addSeconds((int) config('collection-reconciliation.lease_seconds')),
                'attempts' => DB::raw('attempts + 1'),
            ]);

            return min($claims->map(static fn ($claim): int => (int) strtotime($claim->deadline))->all());
        }, 3);
    }

    public function settle(string $owner, string $reason): void
    {
        DB::table('reconciliation_claims')->where('owner', $owner)->update(['owner' => null, 'lease_until' => null, 'reason' => $reason]);
        Log::info('Collection reconciliation outcome', ['reason' => $reason]);
    }

    public function retry(string $owner, string $reason): void
    {
        foreach (DB::table('reconciliation_claims')->where('owner', $owner)->get() as $claim) {
            $exhausted = (int) $claim->attempts >= 3 || strtotime($claim->deadline) <= now()->timestamp;
            $delay = (int) config('collection-reconciliation.retry_seconds.'.max(0, (int) $claim->attempts - 1), 300);
            DB::table('reconciliation_claims')->where('collection_id', $claim->collection_id)->where('owner', $owner)->update([
                'owner' => null, 'lease_until' => null, 'reason' => $exhausted ? 'retry_exhausted' : ($reason === 'budget_exhausted' ? $reason : 'retry'),
                'retry_at' => now()->addSeconds($delay),
            ]);
        }
        Log::info('Collection reconciliation retry', ['reason' => $reason]);
    }
}
