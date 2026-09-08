<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;
use LogicException;

final class RecoveryOwnership
{
    public const array INACTIVE_STATES = ['construction_limit_reached', 'expiry_pending', 'expired_unresolved', 'quarantined', 'unsupported', 'tombstoned', 'absorbed', 'duplicate_policy_discarded', 'coalesced'];

    public function locked(RecoveryWorkClaim $claim): ?object
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ownership_requires_transaction');
        }
        $bundle = DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->lockForUpdate()->first();
        if ($bundle === null || (int) $bundle->revision !== $claim->revision
            || in_array($bundle->state, self::INACTIVE_STATES, true)) {
            return null;
        }
        if (! $this->current($bundle, $claim->stage, $claim->purpose, $claim->payload)) {
            return null;
        }
        $owned = DB::table('obfuscation_recovery_work')->where('id', $claim->id)->where('bundle_id', $claim->bundleId)
            ->where('revision', $claim->revision)->where('claim_token', $claim->token)->where('status', 'claimed')
            ->where('claim_expires_at', '>', now())->lockForUpdate()->first();

        return $owned === null ? null : $bundle;
    }

    /** @param array<string,mixed> $payload */
    public function current(object $bundle, RecoveryStage $stage, string $purpose, array $payload): bool
    {
        if ($stage === RecoveryStage::Publish && $bundle->manifest_verified_at !== null && $bundle->sealed_plan !== null
            && in_array($bundle->state, ['ready', 'publishing', 'published'], true)) {
            return true;
        }
        $publishedEnrichment = false;
        if ($stage === RecoveryStage::Download && $purpose === 'enrichment') {
            $publication = DB::table('obfuscation_recovery_publications')->where('id', $payload['publication_id'] ?? 0)
                ->where('state', 'published')->whereNull('deleted_at')->first();
            if ($publication !== null) {
                $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
                $publishedEnrichment = $plan->bundleId === (int) $bundle->id && $plan->revision === (int) $bundle->revision;
            }
        }
        if ($bundle->capture_generation !== null && ! $publishedEnrichment) {
            $control = DB::table('obfuscation_recovery_controls')->where('scope', 'group:'.$bundle->groups_id)->first();
            $source = DB::table('obfuscation_recovery_controls')->where('scope', 'primary')->first();
            if ($control === null || $source === null || (int) $control->generation !== (int) $bundle->capture_generation
                || $source->epoch !== $bundle->source_epoch) {
                return false;
            }
        }

        return true;
    }
}
