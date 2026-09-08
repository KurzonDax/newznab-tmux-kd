<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Models\Settings;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RecoveryScheduler
{
    /** @phpstan-impure */
    public function allowed(bool $engine): bool
    {
        return ! $engine || (int) Settings::settingValue('is_running') === 1;
    }

    /** @return array<string,int> */
    public function local(RecoveryStage $stage, int $limit = 10, int $seconds = 60, bool $engine = false): array
    {
        $limit = min(10, max(1, $limit));
        $deadline = hrtime(true) + min(60, max(1, $seconds)) * 1000000000;
        $report = [];
        if (! $this->allowed($engine)) {
            return ['engine_stopped' => 1];
        }
        $retention = app(RecoveryRetention::class)->purge(RecoveryConfig::fromSettings());
        $report['expired_headers'] = $retention['headers'];
        $report['compacted_attempts'] = app(RecoveryCompaction::class)->step();
        $report['compacted_catalog_requests'] = RecoveryCatalog::compact();
        $report['compacted_coverage'] = app(RecoveryCompaction::class)->coverage();
        $evidenceRetention = app(RecoveryEvidenceRetention::class)->step();
        $report['expired_evidence'] = $evidenceRetention['evidence'];
        $report['expired_artifacts'] = $evidenceRetention['artifacts'];
        $work = app(RecoveryWork::class);
        $work->reclaimExpired();
        app(RecoverySlots::class)->reap();
        for ($i = 0; $i < $limit && hrtime(true) < $deadline && $this->allowed($engine); $i++) {
            if ($stage === RecoveryStage::Publish) {
                $pending = DB::table('obfuscation_recovery_publications')->where('state', 'published')
                    ->where('initialization_state', 'pending')->whereNull('deleted_at')->orderBy('updated_at')->orderBy('id')->value('id');
                if ($pending !== null) {
                    $result = app(RecoveryBootstrap::class)->run((int) $pending);
                    $report['bootstrap_'.$result] = ($report['bootstrap_'.$result] ?? 0) + 1;
                }
            }
            if (! RecoveryConfig::fromSettings()->enabled) {
                break;
            }
            if ($stage === RecoveryStage::Discover) {
                app(RecoveryLimitResume::class)->step();
                app(RecoveryEnrichmentResume::class)->step();
                app(RecoveryGapPlanner::class)->step();
                app(RecoveryRunRefresh::class)->step();
                app(RecoveryBundleRefresh::class)->step();
            }
            $claim = $work->claim($stage);
            if ($claim === null) {
                continue;
            }
            try {
                $result = $stage === RecoveryStage::Discover
                    ? app(RecoveryPreparation::class)->run($claim)
                    : app(RecoveryPublisher::class)->run($claim);
            } catch (Throwable) {
                $work->defer($claim, 60);
                $result = 'local_failure';
            }
            $report[$result] = ($report[$result] ?? 0) + 1;
        }

        return $report;
    }

    public function download(bool $engine = false): string
    {
        if (! $this->allowed($engine) || ! RecoveryConfig::fromSettings()->enabled) {
            return 'admission_pending';
        }
        $work = app(RecoveryWork::class);
        $claim = $work->claim(RecoveryStage::Download);
        if ($claim === null) {
            return 'idle';
        }
        try {
            return app(RecoveryDownload::class)->run($claim);
        } catch (Throwable) {
            $work->defer($claim, 60);

            return 'worker_failure';
        }
    }
}
