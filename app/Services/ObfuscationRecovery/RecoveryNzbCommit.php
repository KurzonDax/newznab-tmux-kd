<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Models\Release;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class RecoveryNzbCommit
{
    public function publication(int $releaseId): ?object
    {
        if (! Schema::hasTable('obfuscation_recovery_publications')) {
            return null;
        }

        return DB::table('obfuscation_recovery_publications')->where('releases_id', $releaseId)
            ->whereNotIn('state', ['absorbed', 'duplicate_policy_discarded'])->first();
    }

    public function verify(Release $release, string $path, bool $existingFile = false): ?RecoveryNzbReceipt
    {
        $publication = $this->publication((int) $release->id);
        if ($publication === null) {
            return null;
        }
        if (! in_array($publication->state, ['created', 'published'], true) || $publication->guid !== $release->guid || $publication->deleted_at !== null) {
            throw new RuntimeException('recovery_release_association_mismatch');
        }
        $this->assertCurrentPlan($publication);
        if (! $existingFile) {
            $this->assertFormation($publication);
        }
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        $digest = app(RecoveryNzbVerifier::class)->verify($path, $plan);

        return new RecoveryNzbReceipt((int) $publication->id, (int) $release->id, $release->guid, $plan->manifestDigest, $digest, hash('sha256', $publication->sealed_plan));
    }

    public function commit(?RecoveryNzbReceipt $receipt, bool $existingFile = false): void
    {
        if ($receipt === null) {
            return;
        }
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('recovery_nzb_commit_requires_transaction');
        }
        $publication = DB::table('obfuscation_recovery_publications')->where('id', $receipt->publicationId)->lockForUpdate()->first();
        if ($publication === null || ! in_array($publication->state, ['created', 'published'], true)
            || (int) $publication->releases_id !== $receipt->releaseId || $publication->guid !== $receipt->guid
            || $publication->manifest_digest !== $receipt->manifestDigest || hash('sha256', $publication->sealed_plan) !== $receipt->sealedPlanDigest
            || $publication->deleted_at !== null
            || ($publication->nzb_digest !== null && $publication->nzb_digest !== $receipt->nzbDigest)) {
            throw new RuntimeException('recovery_nzb_commit_conflict');
        }
        $this->assertCurrentPlan($publication, true);
        if (! $existingFile) {
            $this->assertFormation($publication);
        }
        $groupId = (int) DB::table('releases')->where('id', $receipt->releaseId)->value('groups_id');
        if (! $existingFile && $publication->state === 'created' && ! RecoveryAdmission::allows($groupId, RecoveryAlgorithm::from($publication->profile))) {
            throw new RecoveryAdmissionPending;
        }
        DB::table('obfuscation_recovery_publications')->where('id', $receipt->publicationId)->update([
            'state' => 'published', 'nzb_digest' => $receipt->nzbDigest, 'updated_at' => now(),
        ]);
        DB::table('obfuscation_recovery_bundles')->where('publication_id', $receipt->publicationId)
            ->where('sealed_plan', $publication->sealed_plan)->whereNotIn('state', RecoveryOwnership::INACTIVE_STATES)
            ->update(['state' => 'published', 'updated_at' => now()]);
    }

    private function assertCurrentPlan(object $publication, bool $lock = false): void
    {
        if ($publication->state === 'published') {
            return;
        }
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        $bundle = DB::table('obfuscation_recovery_bundles')->where('id', $plan->bundleId)->lock($lock)->first();
        if ($bundle === null || (int) $bundle->revision !== $plan->revision
            || $bundle->manifest_verified_at === null || $bundle->sealed_plan !== $publication->sealed_plan
            || in_array($bundle->state, RecoveryOwnership::INACTIVE_STATES, true)
            || ! (new RecoveryPublicationCoverage)->ready($bundle)
            || ! (new RecoveryOwnership)->current($bundle, RecoveryStage::Publish, 'publish', [])) {
            throw new RuntimeException('recovery_nzb_plan_obsolete');
        }
    }

    private function assertFormation(object $publication): void
    {
        if ($publication->state === 'published') {
            return;
        }
        $collection = DB::table('collections')->where('id', $publication->collections_id)->first();
        $reason = $collection === null ? 'collection_missing' : (new RecoveryFormationPolicy)->blockedReason($collection);
        if ($reason !== null) {
            DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->where('state', 'created')
                ->update(['state' => 'policy_blocked', 'reason' => $reason, 'updated_at' => now()]);
            throw new RecoveryAdmissionPending;
        }
    }
}
