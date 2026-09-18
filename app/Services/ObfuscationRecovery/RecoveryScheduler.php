<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Models\Settings;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RecoveryScheduler
{
    /** @phpstan-impure */
    public function allowed(bool $engine): bool
    {
        return ! $engine || Settings::isEngineRunning();
    }

    /**
     * @param  ?Closure(string, array<string, mixed>): void  $observe
     * @return array<string,int>
     */
    public function local(RecoveryStage $stage, int $limit = 10, int $seconds = 60, bool $engine = false, ?Closure $observe = null): array
    {
        $started = hrtime(true);
        $claims = 0;
        $planning = ['gaps_queued' => 0, 'runs_refreshed' => 0, 'bundles_refreshed' => 0, 'frontier' => []];
        $limit = min(10, max(1, $limit));
        $deadline = hrtime(true) + min(60, max(1, $seconds)) * 1000000000;
        $report = [];
        if (! $this->allowed($engine)) {
            $observe?->__invoke('engine_stopped', []);

            return ['engine_stopped' => 1];
        }
        $retention = app(RecoveryRetention::class)->purge(RecoveryConfig::fromSettings());
        $report['expired_headers'] = $retention['headers'];
        if ($stage === RecoveryStage::Discover) {
            $report += app(RecoveryHistoryRetention::class)->step(RecoveryConfig::fromSettings());
        }
        $report['compacted_attempts'] = app(RecoveryCompaction::class)->step();
        $report['compacted_catalog_requests'] = RecoveryCatalog::compact();
        $report['compacted_coverage'] = app(RecoveryCompaction::class)->coverage();
        $evidenceRetention = app(RecoveryEvidenceRetention::class)->step();
        $report['expired_evidence'] = $evidenceRetention['evidence'];
        $report['expired_artifacts'] = $evidenceRetention['artifacts'];
        $work = app(RecoveryWork::class);
        $work->reclaimExpired();
        app(RecoverySlots::class)->reap();
        $observe?->__invoke('housekeeping', $report);
        for ($i = 0; $i < $limit && hrtime(true) < $deadline && $this->allowed($engine); $i++) {
            if ($stage === RecoveryStage::Publish) {
                $pending = DB::table('obfuscation_recovery_publications')->where('state', 'published')
                    ->where('initialization_state', 'pending')->whereNull('deleted_at')->orderBy('updated_at')->orderBy('id')->value('id');
                if ($pending !== null) {
                    $result = app(RecoveryBootstrap::class)->run((int) $pending);
                    $observe?->__invoke('bootstrap', ['publication_id' => (int) $pending, 'result' => $result]);
                    $report['bootstrap_'.$result] = ($report['bootstrap_'.$result] ?? 0) + 1;
                }
                $naming = app(RecoveryNamingReplay::class)->step();
                if ($naming !== null) {
                    $observe?->__invoke('naming', ['result' => $naming]);
                    $report['naming_'.$naming] = ($report['naming_'.$naming] ?? 0) + 1;
                }
            }
            if (! RecoveryConfig::fromSettings()->enabled) {
                break;
            }
            if ($stage === RecoveryStage::Discover) {
                app(RecoveryLimitResume::class)->step();
                app(RecoveryEnrichmentResume::class)->step();
                $planning['gaps_queued'] += app(RecoveryGapPlanner::class)->step(deadline: $deadline);
                $planning['runs_refreshed'] += app(RecoveryRunRefresh::class)->batch(deadline: $deadline);
                $planning['bundles_refreshed'] += app(RecoveryBundleRefresh::class)->step() !== null ? 1 : 0;
                foreach (app(RecoveryFrontierRebuild::class)->step() as $outcome => $count) {
                    $planning['frontier'][$outcome] = ($planning['frontier'][$outcome] ?? 0) + $count;
                    $report[$outcome] = ($report[$outcome] ?? 0) + $count;
                }
            }
            $claim = $work->claim($stage);
            if ($claim === null) {
                continue;
            }
            $claimStarted = hrtime(true);
            try {
                $result = $stage === RecoveryStage::Discover
                    ? app(RecoveryPreparation::class)->run($claim)
                    : app(RecoveryPublisher::class)->run($claim);
            } catch (Throwable $exception) {
                $result = $this->failureReason($exception, 'local_failure');
                $work->defer($claim, 60, $result);
            }
            $claims++;
            $observe?->__invoke('claim', ['bundle_id' => $claim->bundleId, 'revision' => $claim->revision, 'purpose' => $claim->purpose, 'result' => $result, 'seconds' => (hrtime(true) - $claimStarted) / 1e9]);
            $report[$result] = ($report[$result] ?? 0) + 1;
        }

        if ($stage === RecoveryStage::Discover) {
            $observe?->__invoke('planning', $planning);
        }
        $observe?->__invoke('done', ['claims' => $claims, 'seconds' => (hrtime(true) - $started) / 1e9]);

        return $report;
    }

    public function download(bool $engine = false): string
    {
        return $this->downloadDetailed($engine)['outcome'];
    }

    /** @return array{outcome: string, bundle_id: ?int, purpose: ?string, payload: array<string, mixed>, seconds: float} */
    public function downloadDetailed(bool $engine = false): array
    {
        $started = hrtime(true);
        $claim = null;
        if (! $this->allowed($engine) || ! RecoveryConfig::fromSettings()->enabled) {
            $result = 'admission_pending';
        } else {
            $work = app(RecoveryWork::class);
            $claim = $work->claim(RecoveryStage::Download);
            $result = 'idle';
            if ($claim !== null) {
                try {
                    $result = app(RecoveryDownload::class)->run($claim);
                } catch (Throwable $exception) {
                    $result = $this->failureReason($exception, 'worker_failure');
                    $work->defer($claim, 60, $result);
                }
            }
        }

        return ['outcome' => $result, 'bundle_id' => $claim?->bundleId, 'purpose' => $claim?->purpose,
            'payload' => $claim->payload ?? [], 'seconds' => (hrtime(true) - $started) / 1e9];
    }

    private function failureReason(Throwable $exception, string $fallback): string
    {
        return match ($exception->getMessage()) {
            'transfer_receipt_pending', 'transfer_artifact_pending', 'transfer_evidence_pending', 'capture_handoff_pending' => $exception->getMessage(),
            'artifact_missing', 'artifact_integrity_failure', 'artifact_read_failed', 'artifact_storage_unavailable' => 'artifact_unavailable',
            default => $fallback,
        };
    }
}
