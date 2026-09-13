<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;

final class RecoveryFrontierDispatch
{
    /** The caller holds the group, owner and work locks, and owns any supplied claim. */
    public function prepare(object $owner, object $work, ?RecoveryWorkClaim $claim = null): bool
    {
        if ($work->status !== 'pending' && ($claim === null || $work->status !== 'claimed'
            || $work->claim_token !== $claim->token || $work->claim_expires_at <= now())) {
            return true;
        }
        $request = DB::table('obfuscation_recovery_frontier_requests')->where('bundle_id', $owner->id)->lockForUpdate()->first();
        if ($request === null) {
            return false;
        }
        if ($request->reserved_attempt_id !== null || $this->inFlight($request)) {
            return true;
        }
        $payload = json_decode($work->payload, true, flags: JSON_THROW_ON_ERROR);
        $targets = (new RecoveryFrontierTargets)->authorized(DB::connection(), $owner, $payload, true) ?? [];
        $required = [];
        foreach ($targets as $target) {
            $bundle = DB::table('obfuscation_recovery_bundles')->where('id', $target->bundle_id)->first();
            $interval = (new RecoveryFrontierEvidence)->interval(DB::connection(), $bundle, $payload['first'], (new RecoveryFrontierRebuild)->sealed($bundle));
            if ($interval === null) {
                return true;
            }
            $scope = RecoveryPositiveCoverage::scope($owner->source_epoch, (int) $owner->groups_id, (int) $target->capture_generation);
            $envelope = json_decode($target->envelope, true, flags: JSON_THROW_ON_ERROR);
            if (! (new RecoveryFrontierTargets)->evidenceSufficient(DB::connection(), $scope, $target, $interval['first'], $interval['last'])) {
                $required[] = [$bundle, $envelope, $interval];
            }
        }
        if ($request->expires_at <= now() || $required === []) {
            $this->finish($request, $work, $targets === [] ? 'obsolete_target' : 'frontier_reused');

            return false;
        }
        $interval = null;
        $bundles = [];
        foreach ($required as [$bundle, $envelope, $candidate]) {
            if ($interval !== null && [$candidate['first'], $candidate['last']] !== [$interval['first'], $interval['last']]) {
                return true;
            }
            $interval = $candidate;
            $bundles[] = [$bundle, $envelope];
        }
        if ([$interval['first'], $interval['last']] === [$payload['first'], $payload['last']]) {
            return true;
        }
        foreach ($bundles as [$bundle, $envelope]) {
            $outcome = (new RecoveryFrontierRebuild)->queue($bundle, (int) $owner->capture_generation,
                (object) ['expires_at' => $interval['expires_at']], $interval['first'], $interval['last'],
                intdiv($interval['first'] - 1, 20000) * 20000 + 1, $envelope);
            if ($outcome !== 'frontier_rebuild_pending') {
                return true;
            }
        }
        $successor = DB::table('obfuscation_recovery_frontier_requests')->where('budget_owner', $request->budget_owner)
            ->where('capture_generation', $owner->capture_generation)->where('requested_first', $interval['first'])->where('requested_last', $interval['last'])->first();
        $successorOwner = DB::table('obfuscation_recovery_bundles')->where('id', $successor->bundle_id)->first();
        $successorTargets = (new RecoveryFrontierTargets)->authorized(DB::connection(), $successorOwner,
            ['first' => $interval['first'], 'last' => $interval['last'], 'version' => RecoveryFrontiers::VERSION], true) ?? [];
        foreach ($bundles as [$bundle, $envelope]) {
            if (! in_array((int) $bundle->id, array_map(intval(...), array_column($successorTargets, 'bundle_id')), true)) {
                return false;
            }
        }
        $this->finish($request, $work, 'superseded', (int) $successor->id);

        return false;
    }

    public function inFlight(object $request): bool
    {
        $members = (new RecoveryBudgetOwners(new RecoveryIdentity))->members($request->budget_owner);

        return DB::table('obfuscation_recovery_attempts')->whereNull('settled_at')
            ->whereIn('budget_id', DB::table('obfuscation_recovery_budgets')->select('id')->whereIn('owner_digest', $members)
                ->where('purpose', RecoveryFrontierRebuild::PURPOSE))->exists();
    }

    private function finish(object $request, object $work, string $outcome, ?int $successor = null): void
    {
        DB::table('obfuscation_recovery_frontier_requests')->where('id', $request->id)->update([
            'outcome' => $outcome, 'superseded_by' => $successor, 'updated_at' => now(),
        ]);
        DB::table('obfuscation_recovery_work')->where('id', $work->id)->update([
            'status' => 'obsolete', 'result' => $outcome, 'claim_token' => null, 'claim_expires_at' => null, 'updated_at' => now(),
        ]);
        DB::table('obfuscation_recovery_bundles')->where('id', $request->bundle_id)->update([
            'state' => 'frontier_complete', 'reason' => $outcome, 'updated_at' => now(),
        ]);
    }
}
