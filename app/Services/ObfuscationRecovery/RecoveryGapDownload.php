<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Enums\HeaderScanDirection;
use App\Services\Binaries\HeaderParser;
use App\Services\BlacklistService;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecoveryGapDownload
{
    /** @param list<NntpProvider>|null $providers */
    public function run(RecoveryWorkClaim $claim, ?array $providers = null): string
    {
        $work = app(RecoveryWork::class);
        $frontier = $claim->purpose === RecoveryFrontierRebuild::PURPOSE;
        $bundle = DB::transaction(fn (): ?object => (new RecoveryOwnership)->locked($claim), 1);
        $gap = DB::table($frontier ? 'obfuscation_recovery_frontier_requests' : 'obfuscation_recovery_gaps')->where('bundle_id', $claim->bundleId)->first();
        if ($bundle === null || $bundle->kind !== ($frontier ? 'frontier' : 'gap') || $gap === null) {
            return 'obsolete';
        }
        if ($gap->expires_at <= now()) {
            return $this->finish($claim, 'expired_unresolved');
        }
        $first = (int) $gap->requested_first;
        $last = (int) $gap->requested_last;
        $planner = app(RecoveryGapPlanner::class);
        if ($frontier ? (new RecoveryFrontierTargets)->sufficient(DB::connection(), $bundle, $claim->payload)
            : $planner->positive($gap, $first, $last) === [[$first, $last]]) {
            return $this->finish($claim, $frontier ? 'frontier_reused' : 'reused_capture');
        }
        $config = RecoveryConfig::fromSettings();
        $group = DB::table('usenet_groups')->where('id', $gap->groups_id)->first();
        $providers ??= NntpProviderPool::configuredProviders();
        $provider = null;
        foreach ($providers as $entry) {
            if ($entry->isPrimary() && $entry->enabled) {
                $provider = $entry;
                break;
            }
        }
        if ($provider === null || $group === null || ! RecoveryAdmission::allows((int) $gap->groups_id, RecoveryAlgorithm::from($bundle->profile))) {
            $work->defer($claim);

            return 'admission_pending';
        }
        $fingerprint = (new RecoveryIdentity)->digest([$provider->host, (string) $provider->port, (string) $provider->ssl, $provider->username]);
        if (DB::table('obfuscation_recovery_controls')->where('scope', 'primary')->value('fingerprint') !== $fingerprint) {
            $work->defer($claim);

            return 'source_epoch_pending';
        }
        if (! app(RecoveryProviderBackoff::class)->allows([$provider])) {
            $work->defer($claim);

            return 'provider_backoff';
        }
        $slots = app(RecoverySlots::class);
        $slot = $slots->acquire($config);
        if ($slot === null) {
            $work->defer($claim);

            return 'capacity_pending';
        }
        try {
            $budget = app(RecoveryBudget::class);
            $reservation = $budget->reserveGap($claim, $slot, $provider);
            if ($reservation === null) {
                if (! $work->heartbeat($claim)) {
                    return 'obsolete';
                }
                if (! $slots->canStart($slot, RecoveryConfig::fromSettings())) {
                    $work->defer($claim);

                    return 'capacity_pending';
                }
                if ($frontier && $budget->frontierPending($claim)) {
                    $work->defer($claim);

                    return 'range_pending';
                }

                return $this->finish($claim, $frontier ? 'frontier_limit_reached' : 'gap_limit_reached');
            }
            DB::table('obfuscation_recovery_attempts')->where('id', $reservation->attemptId)->where('token', $reservation->token)
                ->update(['provider' => 'position:1']);
            $result = app(RecoveryWire::class)->observeConnections($budget->connectionObserver($reservation))->overview($provider, $group->name, $first, $last);
            $budget->recordTransfer($reservation, $result->transport, $provider->ssl, 65536);
            app(RecoveryProviderBackoff::class)->record($provider, $result->transport);
            if (! $work->heartbeat($claim)) {
                return 'obsolete';
            }
            if ($result->transport->outcome === 'success') {
                $policy = app(BlacklistService::class);
                $parsed = (new HeaderParser($policy))->parse($result->headers, $group->name);
                $context = new RecoveryScanContext((int) $gap->groups_id, $group->name, $gap->source_epoch,
                    (int) $gap->capture_generation, $first, $last, $frontier ? HeaderScanDirection::Repair : HeaderScanDirection::Head, (string) Str::uuid());
                $captured = (new RecoveryCapture(RecoveryConfig::fromSettings(), $policy))->capture(new RecoveryCaptureBatch($result->headers, $parsed['headers']), $context, $claim);
                if ($frontier ? $captured->coverageComplete && (new RecoveryFrontierTargets)->sufficient(DB::connection(), $bundle, $claim->payload)
                    : $planner->positive($gap, $first, $last) === [[$first, $last]]) {
                    return $this->finish($claim, $frontier ? 'frontier_rebuilt' : 'captured');
                }
            }
            if ($reservation->physicalAttempt < 2 && $result->transport->outcome !== 'semantic_failure') {
                $work->defer($claim);

                return 'retry_pending';
            }

            return $this->finish($claim, $frontier ? 'frontier_unresolved' : 'gap_unresolved');
        } finally {
            $slots->release($slot);
        }
    }

    private function finish(RecoveryWorkClaim $claim, string $outcome): string
    {
        return DB::transaction(function () use ($claim, $outcome): string {
            if (! app(RecoveryWork::class)->complete($claim, $outcome)) {
                return 'obsolete';
            }
            $frontier = $claim->purpose === RecoveryFrontierRebuild::PURPOSE;
            DB::table($frontier ? 'obfuscation_recovery_frontier_requests' : 'obfuscation_recovery_gaps')->where('bundle_id', $claim->bundleId)->update(['outcome' => $outcome, 'updated_at' => now()]);
            DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->update(['state' => $frontier ? 'frontier_complete' : 'gap_complete', 'reason' => $outcome, 'updated_at' => now()]);

            return $outcome;
        }, 1);
    }
}
