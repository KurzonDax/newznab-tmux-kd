<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Models\UsenetGroup;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\AudioProcessing\Contracts\AudioProcessingOrchestratorInterface;
use App\Services\AudioProcessing\DTO\AudioProcessingBatchResult;
use App\Services\AudioProcessing\DTO\AudioProcessingResult;
use App\Services\TempWorkspaceService;
use Illuminate\Support\Facades\Log;

/**
 * Claims one bucket's worth of pending music releases and runs them through
 * {@see AudioReleaseProcessor}.
 *
 * The batch shape mirrors the shared path deliberately: same claim token per
 * worker, same per-release try/catch so one poison release cannot stall a whole
 * GUID bucket, same temp workspace that is cleared on the way in and out.
 */
final class AudioProcessingOrchestrator implements AudioProcessingOrchestratorInterface
{
    private string $mainTmpPath = '';

    private string $claimToken = '';

    /** @var array<int, string> */
    private array $groupNameCache = [];

    public function __construct(
        private readonly AudioProcessingConfiguration $config,
        private readonly AudioReleaseProcessor $processor,
        private readonly TempWorkspaceService $tempWorkspace,
    ) {}

    /**
     * @param  null|callable(AudioProcessingResult): void  $onReleaseSettled
     */
    public function start(
        string $guidChar = '',
        string $workerToken = '',
        string $groupID = '',
        ?callable $onReleaseSettled = null,
    ): AudioProcessingBatchResult {
        $this->finish();

        if (! $this->setupTempPath($guidChar, $groupID, $workerToken)) {
            return AudioProcessingBatchResult::empty();
        }

        $this->claimToken = bin2hex(random_bytes(16));
        $releases = AudioCandidateQuery::claimBatch(
            $guidChar,
            $this->config->queryLimit > 0 ? $this->config->queryLimit : 25,
            $this->claimToken,
            $groupID,
            $this->config->maxSizeBytes,
            [
                'id',
                'guid',
                'name',
                'searchname',
                'fromname',
                'proc_pp',
                'size',
                'completion',
                'groups_id',
                'categories_id',
                'predb_id',
                'pp_timeout_count',
                ReleaseClaimant::CLAIM_TOKEN_COLUMN,
            ],
        );

        $results = [];

        foreach ($releases as $release) {
            $startedAt = hrtime(true);

            try {
                $result = $this->processor->process(
                    $release,
                    $this->mainTmpPath,
                    $this->groupName((int) $release->groups_id),
                );
            } catch (\Throwable $e) {
                $result = new AudioProcessingResult(
                    releaseId: (int) ($release->id ?? 0),
                    guid: (string) ($release->guid ?? ''),
                    outcome: ProcessingOutcome::Failed,
                    reason: $e->getMessage(),
                );
                Log::error('Audio postprocessing failed for release '.($release->id ?? '?').': '.$e->getMessage(), [
                    'release_id' => $release->id ?? null,
                    'guid' => $release->guid ?? null,
                    'guid_char' => $guidChar,
                    'exception' => $e,
                ]);
                // Don't rethrow: keep draining the bucket. A release that failed
                // here is left pending and will be re-selected next cycle.
            } finally {
                if ($this->claimToken !== '' && ! empty($release->id)) {
                    // A declined release has already had its token rewritten to the
                    // routing sentinel, and this clear is scoped to the worker's own
                    // token, so it cannot undo that.
                    AudioCandidateQuery::clearClaim((int) $release->id, $this->claimToken);
                }
            }

            $result = $result->withElapsedSeconds($this->elapsedSecondsSince($startedAt));
            $results[] = $result;
            if ($onReleaseSettled !== null) {
                $onReleaseSettled($result);
            }
            if ($this->config->debugMode) {
                Log::debug('Audio release settled', [
                    'release_id' => $result->releaseId,
                    'outcome' => $result->outcome->value,
                    'reason' => $result->reason,
                ]);
            }
        }

        $batchResult = new AudioProcessingBatchResult($results);

        if ($batchResult->pickedCount() > 0) {
            Log::info('Audio postprocessing run finished', [
                'guid_char' => $guidChar,
                'picked' => $batchResult->pickedCount(),
                'previews' => $batchResult->previewCount(),
                'declined' => $batchResult->declinedCount(),
                'crc_failures' => $batchResult->crcFailureCount(),
                'outcomes' => $batchResult->outcomeCounts(),
                'reasons' => $batchResult->reasonCounts(),
            ]);
        }

        return $batchResult;
    }

    private function elapsedSecondsSince(int $startedAtNanoseconds): float
    {
        return (hrtime(true) - $startedAtNanoseconds) / 1_000_000_000;
    }

    public function finish(): void
    {
        if ($this->mainTmpPath !== '') {
            $this->tempWorkspace->clearDirectory($this->mainTmpPath, false);
            $this->mainTmpPath = '';
        }

        $this->claimToken = '';
    }

    private function setupTempPath(string $guidChar, string $groupID, string $workerToken): bool
    {
        try {
            $this->mainTmpPath = $this->tempWorkspace->ensureMainTempPath(
                $this->config->tmpUnrarPath,
                $guidChar,
                $groupID,
                $workerToken !== '' ? $workerToken : bin2hex(random_bytes(16)),
            );
            $this->tempWorkspace->clearDirectory($this->mainTmpPath, true);
        } catch (\Throwable $e) {
            Log::error('Audio post-processing temp path is unavailable', [
                'tmp_unrar_path' => $this->config->tmpUnrarPath,
                'guid_char' => $guidChar,
                'exception' => $e,
            ]);
            $this->mainTmpPath = '';

            return false;
        }

        return true;
    }

    private function groupName(int $groupId): string
    {
        if (! array_key_exists($groupId, $this->groupNameCache)) {
            try {
                $this->groupNameCache[$groupId] = (string) UsenetGroup::getNameByID($groupId);
            } catch (\Throwable) {
                $this->groupNameCache[$groupId] = '';
            }
        }

        return $this->groupNameCache[$groupId];
    }
}
