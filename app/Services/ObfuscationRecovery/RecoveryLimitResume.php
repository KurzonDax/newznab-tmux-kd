<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;

final class RecoveryLimitResume
{
    public function step(): bool
    {
        $config = RecoveryConfig::fromSettings();
        if (! $config->enabled) {
            return false;
        }
        $candidate = DB::table('obfuscation_recovery_bundles')->where('state', 'construction_limit_reached')
            ->orderBy('updated_at')->orderBy('id')->first();
        if ($candidate === null) {
            return false;
        }

        return DB::transaction(function () use ($candidate, $config): bool {
            $bundle = DB::table('obfuscation_recovery_bundles')->where('id', $candidate->id)->where('state', 'construction_limit_reached')->lockForUpdate()->first();
            if ($bundle === null) {
                return false;
            }
            DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->update(['updated_at' => now()]);
            $algorithm = RecoveryAlgorithm::from($bundle->profile);
            if (! RecoveryAdmission::allows((int) $bundle->groups_id, $algorithm)) {
                return false;
            }
            $owners = new RecoveryBudgetOwners(new RecoveryIdentity);
            $owners->locked($bundle->owner_digest);
            $budgets = DB::table('obfuscation_recovery_budgets')->whereIn('owner_digest', $owners->members($bundle->owner_digest))->get();
            $spent = (int) $budgets->where('purpose', 'construction')->sum('debited_bytes');
            $limit = $algorithm === RecoveryAlgorithm::Media ? $config->mediaCandidateBytes : $config->rarCandidateBytes;
            $waiting = DB::table('obfuscation_recovery_work')->where('bundle_id', $bundle->id)->where('revision', $bundle->revision)
                ->where('stage', RecoveryStage::Download->value)->where('result', 'construction_limit_reached')->limit(97)->get();
            foreach ($waiting as $request) {
                $payload = json_decode($request->payload, true, flags: JSON_THROW_ON_ERROR);
                $target = RecoveryConstructionTargets::find($bundle, $payload['message_id']);
                $digest = (new RecoveryIdentity)->digest(['request', $payload['message_id']]);
                $attempts = DB::table('obfuscation_recovery_attempts')->whereIn('budget_id', $budgets->pluck('id')->all())->where('request_digest', $digest)->get();
                if ($target === null || $attempts->count() >= 2 || $attempts->contains('settled_at', null)
                    || $attempts->contains('outcome', 'semantic_failure') || $attempts->contains('outcome', 'success')
                    || RecoveryConstructionTargets::allowance($target['kind'], $algorithm)['reservation'] > $limit - $spent) {
                    continue;
                }
                DB::table('obfuscation_recovery_work')->where('id', $request->id)->where('status', 'completed')
                    ->update(['status' => 'pending', 'result' => null, 'due_at' => now(), 'updated_at' => now()]);
                DB::table('obfuscation_recovery_work')->where('bundle_id', $bundle->id)->where('revision', $bundle->revision)
                    ->where('stage', RecoveryStage::Discover->value)->where('result', 'construction_limit_reached')
                    ->update(['status' => 'pending', 'result' => null, 'due_at' => now(), 'updated_at' => now()]);
                DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)
                    ->update(['state' => 'collecting', 'reason' => null, 'next_action_at' => now(), 'updated_at' => now(),
                        'inactive_since' => null, 'detail_retired_at' => null]);

                return true;
            }

            return false;
        }, 1);
    }
}
