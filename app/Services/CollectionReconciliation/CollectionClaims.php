<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Services\ObfuscationRecovery\RecoveryCollectionOwnership;
use App\Services\Releases\CollectionQuietPredicate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CollectionClaims
{
    public function __construct(private readonly PendingInventory $inventory) {}

    /** @param list<int> $ids */
    public function claim(array $ids, string $owner, string $revision, int $quietHours, ?string $evidenceContext = null): ?int
    {
        sort($ids);
        $inventoryRevision = $revision;
        $revision = self::revision($revision, $evidenceContext);

        return DB::transaction(function () use ($ids, $owner, $revision, $inventoryRevision, $quietHours): ?int {
            $collections = DB::table('collections')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $quiet = CollectionQuietPredicate::build($quietHours, DB::getTablePrefix().'collections');
            if ($collections->count() !== count($ids) || DB::table('collections')->whereIn('id', $ids)->whereRaw($quiet['sql'], $quiet['bindings'])->tap(static fn ($query) => RecoveryCollectionOwnership::exclude($query))->tap(static fn ($query) => CollectionOwnership::excludeArtifactSources($query))->lockForUpdate()->count() !== count($ids)
                || $collections->contains(static fn ($row): bool => (int) $row->filecheck === 4 || (int) $row->filecheck === 5)) {
                return null;
            }
            foreach ($ids as $id) {
                DB::table('reconciliation_claims')->insertOrIgnore(['collection_id' => $id,
                    'deadline' => now()->addSeconds((int) config('collection-reconciliation.decision_seconds'))]);
            }
            $claims = DB::table('reconciliation_claims')->whereIn('collection_id', $ids)->orderBy('collection_id')->lockForUpdate()->get();
            foreach ($claims as $claim) {
                $changed = $claim->revision !== $revision;
                if (($claim->reason === 'deadline' && $claim->revision !== 'changed') || $claim->release_id !== null || (! $changed && strtotime($claim->deadline) <= now()->timestamp && ! in_array($claim->reason, ['budget_exhausted', 'cycle_yield', 'no_available_provider'], true))
                    || ($claim->owner !== null && strtotime($claim->lease_until ?? '') > now()->timestamp)
                    || (! $changed && $claim->retry_at !== null && strtotime($claim->retry_at) > now()->timestamp)
                    || (! $changed && (int) $claim->attempts >= 3)
                    || (! in_array($claim->reason, ['pending', 'retry', 'budget_exhausted', 'cycle_yield', 'no_available_provider'], true) && $claim->revision === $revision)) {
                    return null;
                }
            }
            if (PendingInventory::digest($this->inventory->load($ids)) !== $inventoryRevision) {
                return null;
            }
            foreach ($claims as $claim) {
                if ($claim->revision !== $revision || in_array($claim->reason, ['budget_exhausted', 'cycle_yield', 'no_available_provider'], true)) {
                    DB::table('reconciliation_claims')->where('collection_id', $claim->collection_id)->update([
                        'deadline' => now()->addSeconds((int) config('collection-reconciliation.decision_seconds')),
                        'attempts' => $claim->revision !== $revision ? 0 : (int) $claim->attempts,
                        'retry_at' => null,
                    ]);
                }
            }
            DB::table('reconciliation_claims')->whereIn('collection_id', $ids)->update([
                'owner' => $owner, 'revision' => $revision, 'reason' => 'pending',
                'lease_until' => now()->addSeconds((int) config('collection-reconciliation.lease_seconds')),
            ]);

            return strtotime(DB::table('reconciliation_claims')->whereIn('collection_id', $ids)->min('deadline'));
        }, 3);
    }

    public static function revision(string $inventoryRevision, ?string $evidenceContext = null): string
    {
        return $evidenceContext === null ? $inventoryRevision : hash('sha256', $inventoryRevision.':'.$evidenceContext);
    }

    public function settle(string $owner, string $reason): void
    {
        DB::table('reconciliation_claims')->where('owner', $owner)->update(['owner' => null, 'lease_until' => null, 'reason' => $reason]);
        Log::info('Collection reconciliation outcome', ['reason' => $reason]);
    }

    public function retry(string $owner, string $reason): void
    {
        foreach (DB::table('reconciliation_claims')->where('owner', $owner)->get() as $claim) {
            $deferred = in_array($reason, ['budget_exhausted', 'cycle_yield', 'no_available_provider'], true);
            $permanent = $reason === 'decision_budget_exhausted';
            $attempts = (int) $claim->attempts + (int) (! $deferred && ! $permanent);
            $exhausted = $attempts >= 3 || strtotime($claim->deadline) <= now()->timestamp;
            $delay = (int) config('collection-reconciliation.retry_seconds.'.max(0, $attempts - 1), 300);
            DB::table('reconciliation_claims')->where('collection_id', $claim->collection_id)->where('owner', $owner)->update([
                'owner' => null, 'lease_until' => null, 'reason' => $deferred || $permanent ? $reason : ($exhausted ? 'retry_exhausted' : 'retry'),
                'attempts' => $attempts,
                'retry_at' => $deferred || $permanent ? null : now()->addSeconds($delay),
            ]);
        }
        Log::info('Collection reconciliation retry', ['reason' => $reason]);
    }
}
