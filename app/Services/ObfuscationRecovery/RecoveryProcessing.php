<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Models\Release;
use App\Services\AdditionalProcessing\DTO\ReleaseProcessingResult;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use Illuminate\Support\Facades\DB;

final class RecoveryProcessing
{
    public function __construct(private readonly RecoveryHeads $heads, private readonly RecoveryHeadIndex $index,
        private readonly RecoveryCachedReader $reader, private readonly RecoveryArchiveInspection $archives) {}

    public function run(object $publication, ReleaseProcessingContext $context, MediaExtractionService $media): ReleaseProcessingResult
    {
        $token = $context->release->{ReleaseClaimant::CLAIM_TOKEN_COLUMN};
        $inspection = RecoveryInspection::acquire($publication, is_string($token) ? $token : null);
        if ($inspection === null) {
            return new ReleaseProcessingResult((int) $context->release->id, $context->release->guid,
                ProcessingOutcome::RecoveryEvidencePending, reason: 'inspection_busy', nextAttemptAt: now()->addMinute()->toIso8601String());
        }
        try {
            return $this->inspect($publication, $context, $media, $inspection);
        } catch (\RuntimeException $error) {
            if ($error->getMessage() !== 'recovery_inspection_claim_lost') {
                throw $error;
            }

            return new ReleaseProcessingResult((int) $context->release->id, $context->release->guid,
                ProcessingOutcome::RecoveryEvidencePending, reason: 'inspection_claim_lost', nextAttemptAt: now()->addMinute()->toIso8601String());
        } finally {
            $inspection->release();
        }
    }

    private function inspect(object $publication, ReleaseProcessingContext $context, MediaExtractionService $media, RecoveryInspection $inspection): ReleaseProcessingResult
    {
        $releaseId = (int) $context->release->id;
        if ($publication->state !== 'published' || $publication->deleted_at !== null) {
            return new ReleaseProcessingResult($releaseId, $context->release->guid, ProcessingOutcome::NoUsefulArtifacts, reason: 'publication_unavailable');
        }
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        if ($publication->initialization_state === 'pending') {
            app(RecoveryBootstrap::class)->run((int) $publication->id, $inspection);
        }
        $records = $this->index->forPublication($publication);
        $files = array_values(array_filter($plan->files, static fn (RecoveryFilePlan $file): bool => $file->role !== RecoveryFileRole::Index));
        usort($files, static fn (RecoveryFilePlan $a, RecoveryFilePlan $b): int => $plan->algorithm === RecoveryAlgorithm::Rar
            ? strnatcasecmp($a->displayName, $b->displayName) : strcmp($a->identity, $b->identity));
        $pending = $available = false;
        $unresolved = [];
        foreach ($files as $file) {
            $row = DB::table('obfuscation_recovery_files')->where('bundle_id', $plan->bundleId)->where('revision', $plan->revision)->where('file_id', $file->identity);
            if ((clone $row)->where('enrichment_outcome', 'media_evidence_available')->exists()) {
                $available = true;

                continue;
            }
            $maximum = $plan->algorithm === RecoveryAlgorithm::Rar || $plan->multiMediaInventory() ? 2097152 : 4194304;
            $prefix = $this->reader->readRecords($records[$file->identity], $file, $maximum);
            $outcome = 'cached_anchor_available';
            $reason = null;
            if ($file->role === RecoveryFileRole::RarVolume) {
                $outcome = $this->archives->inspect($publication, $file, $prefix, $inspection);
                if ($outcome === 'discarded') {
                    return new ReleaseProcessingResult($releaseId, $context->release->guid, ProcessingOutcome::Discarded);
                }
                $available = $available || $outcome === 'partial_archive_listing';
            } else {
                $path = $context->tmpPath.'/recovered-'.$file->identity.'.'.$file->format;
                if (file_put_contents($path, $prefix->data) !== strlen($prefix->data)) {
                    throw new \RuntimeException('recovered_head_storage_failed');
                }
                try {
                    if ($media->getMediaInfo($path, $releaseId, recoveryFileId: $file->identity, recoveryInspection: $inspection)) {
                        $available = true;

                        continue;
                    }
                } finally {
                    unlink($path);
                }
                $outcome = $file->format === 'mp4' ? 'metadata_outside_bounded_head' : 'bounded_head_unavailable';
            }
            if (in_array($outcome, ['archive_listing_unavailable', 'archive_head_unavailable', 'metadata_outside_bounded_head', 'bounded_head_unavailable'], true)) {
                $unresolved[] = ['file_id' => $file->identity, 'needed_bytes' => min($maximum, max(716800, strlen($prefix->data) + 1)), 'outcome' => $outcome];
            }
            $inspection->mutate(fn (): int => $row->update(['enrichment_outcome' => $outcome, 'enrichment_reason' => $reason, 'updated_at' => now()]));
        }
        foreach ($unresolved as $candidate) {
            $outcome = $candidate['outcome'];
            $head = $this->heads->read($releaseId, $candidate['file_id'], $candidate['needed_bytes'], $inspection);
            $pending = $pending || $head->pending();
            $reason = $head->outcome;
            if ($head->pending() || ($head->outcome !== 'head_available' && $outcome !== 'metadata_outside_bounded_head')) {
                $outcome = $head->outcome;
            }
            $inspection->mutate(fn (): int => DB::table('obfuscation_recovery_files')->where('bundle_id', $plan->bundleId)
                ->where('revision', $plan->revision)->where('file_id', $candidate['file_id'])
                ->update(['enrichment_outcome' => $outcome, 'enrichment_reason' => $reason, 'updated_at' => now()]));
        }
        $token = $context->release->{ReleaseClaimant::CLAIM_TOKEN_COLUMN};
        if ($pending) {
            $inspection->mutate(fn () => ReleaseClaimant::clearClaim($releaseId, is_string($token) ? $token : null));

            return new ReleaseProcessingResult($releaseId, $context->release->guid, ProcessingOutcome::RecoveryEvidencePending,
                reason: 'recovery_evidence_pending', nextAttemptAt: now()->addMinute()->toIso8601String());
        }
        $inspection->mutate(function () use ($releaseId, $publication, $available, $token): void {
            Release::query()->whereKey($releaseId)->update(['haspreview' => 0]);
            DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->where('state', 'published')->update([
                'enrichment_outcome' => $available ? 'cached_evidence_available' : 'bounded_evidence_unavailable',
                'enrichment_next_attempt_at' => null, 'updated_at' => now(),
            ]);
            ReleaseClaimant::clearClaim($releaseId, is_string($token) ? $token : null);
        });

        return new ReleaseProcessingResult($releaseId, $context->release->guid,
            $available ? ProcessingOutcome::Completed : ProcessingOutcome::NoUsefulArtifacts, artifactsCreated: $available,
            reason: $available ? 'cached_evidence_available' : 'bounded_evidence_unavailable');
    }
}
