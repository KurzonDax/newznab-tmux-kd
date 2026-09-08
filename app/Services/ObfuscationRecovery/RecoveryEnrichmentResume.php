<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Enums\ObfuscationRecoveryProfile;
use App\Models\Release;
use App\Services\NfoService;
use Illuminate\Support\Facades\DB;

final class RecoveryEnrichmentResume
{
    public function step(): bool
    {
        $config = RecoveryConfig::fromSettings();
        if (! $config->enabled || ! $config->enrichmentEnabled) {
            return false;
        }
        $target = DB::table('obfuscation_recovery_targets')->where('status', 'completed')->where('outcome', 'enrichment_limit_reached')
            ->orderBy('updated_at')->orderBy('id')->first();
        if ($target === null) {
            return false;
        }
        DB::table('obfuscation_recovery_targets')->where('id', $target->id)->update(['updated_at' => now()]);
        $publication = DB::table('obfuscation_recovery_publications')->where('id', $target->publication_id)
            ->where('state', 'published')->whereNull('deleted_at')->first();
        $inspection = $publication === null ? null : RecoveryInspection::acquire($publication);
        if ($inspection === null) {
            return false;
        }
        try {
            return $inspection->mutate(function () use ($publication, $target): bool {
                $config = RecoveryConfig::fromSettings();
                $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
                $bundle = DB::table('obfuscation_recovery_bundles')->where('id', $plan->bundleId)->lockForUpdate()->first();
                if ($bundle === null || (int) $bundle->revision !== $plan->revision) {
                    return false;
                }
                $selection = ObfuscationRecoveryProfile::tryFrom(DB::table('usenet_groups')->where('id', $bundle->groups_id)->value('obfuscation_recovery_profile') ?? '');
                if (! $config->enabled || ! $config->enrichmentEnabled || ! $selection?->permits($plan->algorithm->selection())) {
                    return false;
                }
                $current = DB::table('obfuscation_recovery_targets')->where('id', $target->id)->where('status', 'completed')
                    ->where('outcome', 'enrichment_limit_reached')->lockForUpdate()->first();
                if ($current === null) {
                    return false;
                }
                $owners = new RecoveryBudgetOwners(new RecoveryIdentity);
                $owners->locked($bundle->owner_digest);
                $budgets = DB::table('obfuscation_recovery_budgets')->whereIn('owner_digest', $owners->members($bundle->owner_digest))->get();
                $attempts = DB::table('obfuscation_recovery_attempts')->whereIn('budget_id', $budgets->pluck('id'))
                    ->where('request_digest', $target->request_digest)->get();
                if ($attempts->count() >= 2 || $attempts->contains('settled_at', null)
                    || $attempts->contains('outcome', 'semantic_failure')
                    || $attempts->whereIn('budget_id', $budgets->where('purpose', 'enrichment')->pluck('id'))->contains('outcome', 'success')) {
                    return false;
                }
                $spent = (int) $budgets->where('purpose', 'enrichment')->sum('debited_bytes');
                $requests = DB::table('obfuscation_recovery_targets')->where('publication_id', $publication->id)->where('file_id', $target->file_id)->pluck('request_digest');
                $fileSpent = (int) DB::table('obfuscation_recovery_attempts')->whereIn('budget_id', $budgets->where('purpose', 'enrichment')->pluck('id'))
                    ->whereIn('request_digest', $requests)->sum('debited_bytes');
                $fileLimit = $plan->algorithm === RecoveryAlgorithm::Rar || $plan->multiMediaInventory() ? 2097152 : 4194304;
                if ($config->enrichmentReleaseBytes - $spent < 2097152 || $fileLimit - $fileSpent < 2097152) {
                    return false;
                }
                $work = DB::table('obfuscation_recovery_work')->where('bundle_id', $bundle->id)->where('revision', $plan->revision)
                    ->where('purpose', 'enrichment')->where('status', 'completed')->where('result', 'enrichment_limit_reached')
                    ->orderBy('id')->limit(8)->get()->first(static function (object $row) use ($target): bool {
                        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);

                        return ($payload['message_id'] ?? null) === $target->message_id && ($payload['publication_id'] ?? null) === (int) $target->publication_id;
                    });
                if ($work === null) {
                    return false;
                }
                DB::table('obfuscation_recovery_work')->where('id', $work->id)->update(['status' => 'pending', 'result' => null, 'due_at' => now(), 'updated_at' => now()]);
                DB::table('obfuscation_recovery_targets')->where('id', $target->id)->update(['status' => 'pending', 'outcome' => null, 'updated_at' => now()]);
                DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update([
                    'enrichment_outcome' => 'enrichment_pending', 'enrichment_next_attempt_at' => now()->addMinute(), 'updated_at' => now(),
                ]);
                Release::query()->whereKey($publication->releases_id)->update(['haspreview' => -1]);
                if ($publication->nfo_outcome === 'bounded_nfo_unavailable') {
                    Release::query()->whereKey($publication->releases_id)->where('nfostatus', NfoService::NFO_NONFO)->update(['nfostatus' => NfoService::NFO_UNPROC]);
                }

                return true;
            });
        } finally {
            $inspection->release();
        }
    }
}
