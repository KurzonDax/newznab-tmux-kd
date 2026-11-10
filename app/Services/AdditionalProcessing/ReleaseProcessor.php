<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Enums\ImagerySkipArtifact;
use App\Enums\NzbParseFailure;
use App\Models\UsenetGroup;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\AdditionalWorkPlan;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\DTO\ArchiveMoovTail;
use App\Services\AdditionalProcessing\DTO\ReleaseProcessingResult;
use App\Services\AdditionalProcessing\DTO\UnknownPayloadCandidate;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\Enums\Mp4MoovSpliceStatus;
use App\Services\AdditionalProcessing\Enums\PayloadClassification;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\Enums\ProcessingStage;
use App\Services\AdditionalProcessing\State\ArchivePartTail;
use App\Services\AdditionalProcessing\State\CompressedTopUpFetch;
use App\Services\AdditionalProcessing\State\PayloadSniffRun;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ProcessingMetrics;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\DTO\YencArticleMetadata;
use App\Services\MediaInfo\DTO\MediaInfoProbeContext;
use App\Services\MediaInfo\Enums\MediaInfoSourceCompleteness;
use App\Services\MediaInfo\Enums\MediaInfoSourceKind;
use App\Services\ObfuscationRecovery\RecoveryIdentityPolicy;
use App\Services\ObfuscationRecovery\RecoveryProcessing;
use App\Services\ReleaseImageService;
use App\Services\Releases\DynamicPreviewBudgetPolicy;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\TempWorkspaceService;
use dariusiii\rarinfo\RarInfo;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Stateless release processor for additional post-processing.
 */
class ReleaseProcessor
{
    private const string NESTED_ARCHIVE_PATTERN = '/.*\.([rz]\d{2,}|rar|zipx?)($|[^a-z0-9])|\.0{0,2}1$/i';

    /**
     * @var array<int, string>
     */
    private array $groupNameCache = [];

    public function __construct(
        private readonly ProcessingConfiguration $config,
        private readonly NzbContentParser $nzbParser,
        private readonly AdditionalWorkPlanner $workPlanner,
        private readonly ArchiveExtractionService $archiveService,
        private readonly MediaExtractionService $mediaService,
        private readonly UsenetDownloadService $downloadService,
        private readonly ReleaseFileManager $releaseManager,
        private readonly ReleaseFilesArchiveFallback $archiveFallback,
        private readonly TempWorkspaceService $tempWorkspace,
        private readonly ConsoleOutputService $output,
        private readonly ?ReleaseSearchSyncCoordinator $searchSyncCoordinator = null,
        private readonly ?PersistenceMetricsCollector $persistenceMetricsCollector = null,
        private readonly PreviewGenerationPolicy $previewPolicy = new PreviewGenerationPolicy,
        private readonly PayloadSniffer $payloadSniffer = new PayloadSniffer,
        private readonly Mp4MoovSplicer $mp4MoovSplicer = new Mp4MoovSplicer,
        private readonly DynamicPreviewBudgetPolicy $dynamicBudgetPolicy = new DynamicPreviewBudgetPolicy,
        private readonly VideoHeadProbe $headProbe = new VideoHeadProbe,
        private readonly VideoDecodableLengthProbe $decodableLengthProbe = new VideoDecodableLengthProbe,
        private readonly FreeDiskGuard $freeDiskGuard = new FreeDiskGuard,
        private readonly ReleaseImageService $releaseImage = new ReleaseImageService,
        private readonly UnknownPayloadCandidateSelector $candidateSelector = new UnknownPayloadCandidateSelector,
    ) {}

    public function process(ReleaseProcessingContext $context, string $mainTmpPath): ReleaseProcessingResult
    {
        $metrics = new ProcessingMetrics;
        $releaseId = (int) $context->release->id;
        $persistenceMetricsCollector = $this->persistenceMetricsCollector ?? new PersistenceMetricsCollector;
        $searchSyncCoordinator = $this->searchSyncCoordinator
            ?? new ReleaseSearchSyncCoordinator(
                $persistenceMetricsCollector,
            );

        $persistenceMetricsCollector->beginReleaseScope($releaseId);
        $searchSyncCoordinator->beginReleaseScope($releaseId);
        $this->downloadService->beginReleaseScope();

        try {
            $result = $this->processRelease($context, $mainTmpPath, $metrics);
        } finally {
            $context->releaseDownloadedArchives();
            try {
                $searchSyncCoordinator->finishReleaseScope();
            } finally {
                $persistenceMetrics = $persistenceMetricsCollector->finishReleaseScope();
                $downloadMetrics = $this->downloadService->finishReleaseScope();
            }
        }

        return $result->withPerformance(
            $metrics->elapsedSeconds(),
            $metrics->stageDurations(),
            $downloadMetrics,
            $persistenceMetrics,
        );
    }

    private function processRelease(
        ReleaseProcessingContext $context,
        string $mainTmpPath,
        ProcessingMetrics $metrics,
    ): ReleaseProcessingResult {
        $release = $context->release;

        $this->output->echoReleaseStart($release->id, $release->size);
        $this->output->setProcessTitle((int) $release->id);

        try {
            $context->tmpPath = $metrics->measure(
                ProcessingStage::WorkspacePreparation,
                fn (): string => $this->tempWorkspace->createReleaseTempFolder($mainTmpPath, $release->guid),
            );
        } catch (\Throwable $e) {
            $this->output->warning('Unable to prepare release temp directory: '.$e->getMessage());

            return $this->result(
                $context,
                ProcessingOutcome::TemporaryWorkspaceUnavailable,
                reason: $e->getMessage(),
            );
        }

        try {
            $recovery = (new RecoveryIdentityPolicy)->publication((int) $release->id);
            if ($recovery !== null) {
                return app(RecoveryProcessing::class)->run($recovery, $context, $this->mediaService);
            }
            $releaseNameChanged = false;
            $releaseNeededNfo = false;
            $nzbResult = $metrics->measure(
                ProcessingStage::NzbParsing,
                fn (): array => $this->nzbParser->parseNzb($release->guid),
            );
            $context->nzbPassword = $nzbResult['password'] ?? null;
            if ($nzbResult['error'] !== null) {
                $this->output->warning($nzbResult['error']);

                if (($nzbResult['failure'] ?? null) === NzbParseFailure::StorageUnavailable) {
                    return $this->result(
                        $context,
                        ProcessingOutcome::StorageUnavailable,
                        reason: $nzbResult['error'],
                    );
                }

                $this->releaseManager->deleteRelease($release);

                return $this->result(
                    $context,
                    ProcessingOutcome::DeletedBrokenNzb,
                    reason: $nzbResult['error'],
                );
            }

            $context->nzbContents = array_values($nzbResult['contents']);
            if (($timeoutResult = $this->processingTimeoutResult($context, $metrics)) !== null) {
                return $timeoutResult;
            }

            $releaseNameChanged = $metrics->measure(
                ProcessingStage::ReleaseInitialization,
                function () use ($context): bool {
                    $this->initializeContext($context);

                    return $this->releaseManager->processReleaseNameFromNzbContents($context->nzbContents, $context);
                },
            );
            $releaseNeededNfo = $context->releaseHasNoNFO;
            $bookFlood = $metrics->measure(
                ProcessingStage::MessageIdSelection,
                fn (): bool => $this->prepareMessageIds($context),
            );

            if ($this->shouldProcessDownloads($context)) {
                $metrics->measure(
                    ProcessingStage::DirectDownloads,
                    fn () => $this->processMessageIdDownloads($context),
                );
                if (($timeoutResult = $this->processingTimeoutResult($context, $metrics)) !== null) {
                    return $timeoutResult;
                }

                if (! $bookFlood && $context->workPlan?->unknownPayloadCandidates !== []) {
                    $metrics->measure(
                        ProcessingStage::PayloadSniffing,
                        fn () => $this->processUnknownPayloadCandidates($context),
                    );
                    if (($timeoutResult = $this->processingTimeoutResult($context, $metrics)) !== null) {
                        return $timeoutResult;
                    }
                }

                if (! $bookFlood && $context->nzbHasCompressedFile) {
                    $triedCompressedMids = [];
                    $metrics->measure(
                        ProcessingStage::ArchiveDownloads,
                        function () use ($context, &$triedCompressedMids): void {
                            $this->processNzbCompressedFiles($context, false, $triedCompressedMids);
                        },
                    );
                    if (($discardedResult = $this->discardedResult($context)) !== null) {
                        return $discardedResult;
                    }
                    if (($timeoutResult = $this->processingTimeoutResult($context, $metrics)) !== null) {
                        return $timeoutResult;
                    }

                    if ($this->config->fetchLastFiles) {
                        $metrics->measure(
                            ProcessingStage::ArchiveDownloads,
                            function () use ($context, &$triedCompressedMids): void {
                                $this->processNzbCompressedFiles($context, true, $triedCompressedMids);
                            },
                        );
                        if (($discardedResult = $this->discardedResult($context)) !== null) {
                            return $discardedResult;
                        }
                        if (($timeoutResult = $this->processingTimeoutResult($context, $metrics)) !== null) {
                            return $timeoutResult;
                        }
                    }

                    if (! $context->releaseHasPassword) {
                        $metrics->measure(
                            ProcessingStage::ExtractedFiles,
                            fn () => $this->processExtractedFiles($context),
                        );
                        if (($discardedResult = $this->discardedResult($context)) !== null) {
                            return $discardedResult;
                        }
                        if (($timeoutResult = $this->processingTimeoutResult($context, $metrics)) !== null) {
                            return $timeoutResult;
                        }
                    }
                }

                $metrics->measure(ProcessingStage::ArchiveFallbacks, function () use ($context): void {
                    if (! $context->foundJPGSample && $this->config->processJPGSample) {
                        $this->archiveFallback->processJpgFromReleaseFiles($context);
                    }

                    if ($context->releaseHasNoNFO) {
                        $this->archiveFallback->processNfoFromDownloadedArchives($context);
                    }

                    if ($context->releaseHasNoNFO) {
                        $this->archiveFallback->processNfoFromReleaseFiles($context);
                    }
                });
            }

            if (($discardedResult = $this->discardedResult($context)) !== null) {
                return $discardedResult;
            }

            $metrics->measure(
                ProcessingStage::Finalization,
                fn () => $this->releaseManager->finalizeRelease($context, $this->config->processPasswords),
            );

            $artifactsCreated = $releaseNameChanged || $this->createdArtifacts($context, $releaseNeededNfo);
            $outcome = match (true) {
                $context->releaseHasPassword => ProcessingOutcome::Passworded,
                $context->groupUnavailable => ProcessingOutcome::GroupUnavailable,
                ! $artifactsCreated => ProcessingOutcome::NoUsefulArtifacts,
                default => ProcessingOutcome::Completed,
            };

            return $this->result(
                $context,
                $outcome,
                $artifactsCreated,
                reason: match ($outcome) {
                    ProcessingOutcome::Passworded => 'Password protection was detected.',
                    ProcessingOutcome::GroupUnavailable => 'The release group was unavailable during processing.',
                    ProcessingOutcome::NoUsefulArtifacts => 'Processing completed without creating useful artifacts.',
                    default => '',
                },
            );
        } finally {
            if ($context->tmpPath !== '') {
                $metrics->measure(
                    ProcessingStage::WorkspaceCleanup,
                    fn () => $this->tempWorkspace->clearDirectory($context->tmpPath, false),
                );
            }
        }
    }

    private function shouldProcessDownloads(ReleaseProcessingContext $context): bool
    {
        return $this->config->processPasswords
            || $this->config->processThumbnails
            || $this->config->processMediaInfo
            || $this->config->processVideo
            || $this->config->processJPGSample
            || $context->workPlan->unknownPayloadCandidates !== [];
    }

    private function prepareMessageIds(ReleaseProcessingContext $context): bool
    {
        $workPlan = $this->workPlanner->plan(
            $context->nzbContents,
            $context->releaseGroupName,
            (int) $context->release->id,
        );
        $context->workPlan = $workPlan;
        $context->nzbHasCompressedFile = $workPlan->hasCompressedFile();
        $context->sampleMessageIDs = $workPlan->sampleMessageIds;
        $context->jpgMessageIDs = $workPlan->jpgMessageIds;
        $context->mediaInfoMessageIDs = $workPlan->mediaInfoMessageIds;

        return $workPlan->bookFlood;
    }

    private function initializeContext(ReleaseProcessingContext $context): void
    {
        // Per-root Preview Generation toggle (ADR 0004): AND-ed with the
        // site-wide switches, it can only disable the two ffmpeg artifacts
        // (Generated Preview + Generated Sample Video) and the sample-article
        // downloads dedicated to them. Pre-marking the found flags makes every
        // downstream sample/video step skip naturally; mediainfo, passwords and
        // Extracted Sample Images are untouched.
        $previewGenerationAllowed = $this->previewPolicy
            ->generationEnabledForCategory((int) $context->release->categories_id);
        $context->previewGenerationSkippedByPolicy = ! $previewGenerationAllowed;

        // Free-disk guard (ADR 0013): during a squeeze on the covers volume no
        // imagery is produced at all, and the artifacts this release would
        // otherwise have been given are recorded so an operator can requeue it
        // once space is reclaimed. The Clip keeps its own softer response.
        $context->imagerySkippedByDiskGuard = $this->suppressedImagery($previewGenerationAllowed);
        $imageryAllowed = $context->imagerySkippedByDiskGuard === [];

        $context->initializeFromConfig(
            $this->config->processVideo && $previewGenerationAllowed,
            $this->config->processMediaInfo,
            $this->config->processJPGSample && $imageryAllowed,
            $this->config->processThumbnails && $previewGenerationAllowed && $imageryAllowed
        );

        $context->passwordStatus = ReleaseBrowseService::PASSWD_NONE;
        $context->releaseHasPassword = false;
        try {
            $groupId = (int) $context->release->groups_id;
            if (! array_key_exists($groupId, $this->groupNameCache)) {
                $this->groupNameCache[$groupId] = UsenetGroup::getNameByID($groupId);
            }
            $context->releaseGroupName = $this->groupNameCache[$groupId];
        } catch (\Throwable) {
            $context->releaseGroupName = '';
        }
        $context->releaseHasNoNFO = (int) $context->release->nfostatus !== 1;
        $context->resetMessageIDs();
        $context->resetCounters();
    }

    /**
     * The imagery this release would have been given, when the Free-disk guard
     * refuses; an empty list whenever the guard allows, imagery is switched off
     * site-wide, or the root category has Preview Generation disabled. Nothing
     * to suppress means nothing to record and nothing to requeue.
     *
     * @return list<ImagerySkipArtifact>
     */
    private function suppressedImagery(bool $previewGenerationAllowed): array
    {
        $suppressed = [];
        if ($this->config->processJPGSample) {
            $suppressed[] = ImagerySkipArtifact::Sample;
        }
        if ($this->config->processThumbnails && $previewGenerationAllowed) {
            $suppressed[] = ImagerySkipArtifact::Preview;
        }

        // Nothing to suppress means nothing to guard: do not stat the disk for
        // a release that was never going to be given imagery.
        if ($suppressed === [] || $this->freeDiskGuard->allows($this->releaseImage->jpgSavePath)) {
            return [];
        }

        return $suppressed;
    }

    private function processingTimeoutResult(
        ReleaseProcessingContext $context,
        ProcessingMetrics $metrics,
    ): ?ReleaseProcessingResult {
        if (! $context->isTimedOut($this->config->releaseProcessingTimeout)) {
            return null;
        }

        if ($context->passwordVerdict !== null) {
            $this->releaseManager->finalizeRelease($context, $this->config->processPasswords);

            return $this->result($context, ProcessingOutcome::TimedOut,
                reason: 'Processing timed out after bounded archive inspection.');
        }

        $deleted = $metrics->measure(
            ProcessingStage::TimeoutHandling,
            fn (): bool => $this->releaseManager->handleReleaseTimeout(
                $context->release,
                $this->config->maxPpTimeoutCount,
            ),
        );

        if ($deleted) {
            $this->output->echoReleaseTimeoutDeleted(
                $context->release->id,
                (int) ($context->release->pp_timeout_count ?? 0) + 1
            );
        } else {
            $this->output->echoReleaseTimeout($context->release->id, $context->getElapsedSeconds());
        }

        if ($context->tmpPath !== '') {
            $this->tempWorkspace->clearDirectory($context->tmpPath, false);
        }

        return $this->result(
            $context,
            $deleted ? ProcessingOutcome::DeletedAfterTimeout : ProcessingOutcome::TimedOut,
            reason: $deleted
                ? 'The release was deleted after reaching the post-processing timeout limit.'
                : 'The release exceeded the post-processing timeout.',
        );
    }

    private function createdArtifacts(ReleaseProcessingContext $context, bool $releaseNeededNfo): bool
    {
        // Policy-skipped previews pre-mark foundVideo/foundSample; they must
        // not count as created artifacts. A Free-disk guard skip pre-marks the
        // two imagery flags for the same reason and needs the same exclusion.
        $previewGenerationAllowed = ! $context->previewGenerationSkippedByPolicy;
        $imageryAttempted = $context->imagerySkippedByDiskGuard === [];

        return $context->releaseFilesChanged
            || $context->foundPAR2Info
            || ($releaseNeededNfo && ! $context->releaseHasNoNFO)
            || ($this->config->processVideo && $previewGenerationAllowed && $context->foundVideo)
            || ($this->config->processThumbnails && $previewGenerationAllowed && $imageryAttempted && $context->foundSample)
            || ($this->config->processJPGSample && $imageryAttempted && $context->foundJPGSample)
            || ($this->config->processMediaInfo && $context->foundMediaInfo);
    }

    private function result(
        ReleaseProcessingContext $context,
        ProcessingOutcome $outcome,
        bool $artifactsCreated = false,
        string $reason = '',
    ): ReleaseProcessingResult {
        return new ReleaseProcessingResult(
            releaseId: (int) $context->release->id,
            guid: (string) $context->release->guid,
            outcome: $outcome,
            artifactsCreated: $artifactsCreated,
            releaseFilesAdded: $context->addedFileInfo,
            reason: $reason,
            duplicateMessageIdCount: $context->duplicateMessageIdCount(),
            unsupportedReasons: $context->unsupportedReasons(),
            payloadSniffMetrics: $context->payloadSniffMetrics,
            mp4TailMetrics: $context->mp4TailMetrics,
        );
    }

    private function processUnknownPayloadCandidates(ReleaseProcessingContext $context): void
    {
        $run = new PayloadSniffRun;

        foreach ($context->workPlan->unknownPayloadCandidates as $candidate) {
            if ($context->isTimedOut($this->config->releaseProcessingTimeout)) {
                break;
            }
            $step = $this->sniffCandidate($candidate, $context, $run);
            if ($step === 'stop') {
                break;
            }
            $run->foundContainer = $run->foundContainer || $step === 'found';
        }

        $this->searchSplitSet($context, $run);

        $totalFiles = (int) ($context->nzbContents[0]['nzbFileCount'] ?? count($context->nzbContents));
        $context->purePar2Sidecar = $totalFiles > 0 && count($run->deferredPar2) === $totalFiles;
        foreach ($run->deferredPar2 as $payload) {
            $this->processSniffedPar2($payload, $context);
        }

        $deferredArchives = $run->deferredArchives;
        usort(
            $deferredArchives,
            static fn (array $left, array $right): int => $left['candidate']->sourceIndex <=> $right['candidate']->sourceIndex,
        );
        foreach ($deferredArchives as $archive) {
            if ($context->isTimedOut($this->config->releaseProcessingTimeout)) {
                return;
            }

            $this->processCompressedData(
                $archive['payload'],
                $context,
                false,
                $archive['candidate']->title,
            );
        }
    }

    /**
     * Sniffs one candidate's first segment and routes it to its handler:
     * 'found' for an archive or a video, 'stop' when the group is unavailable.
     *
     * @return 'next'|'found'|'stop'
     */
    private function sniffCandidate(UnknownPayloadCandidate $candidate, ReleaseProcessingContext $context, PayloadSniffRun $run): string
    {
        $result = $this->downloadFirstSegment($candidate, $context, $run);
        if ($result['groupUnavailable']) {
            return 'stop';
        }

        if (! $result['success'] || ! is_string($result['data'])) {
            return 'next';
        }

        $payload = $result['data'];
        $sniffResult = $this->payloadSniffer->classify($payload);
        $classification = $sniffResult->classification;
        $context->recordPayloadClassification($classification);
        $run->sidecarEvidence->queueClassification($context, $candidate, $classification->value, $payload);
        if ($classification === PayloadClassification::Par2) {
            $run->deferredPar2[] = $payload;

            return 'next';
        }
        $run->sidecarEvidence->queuePrefix($context, $candidate, $payload, $result['metadata'] ?? null);

        if (in_array($classification, [PayloadClassification::Rar, PayloadClassification::Zip, PayloadClassification::SevenZip], true)) {
            $archiveCandidate = new ArchiveCandidate(
                title: $candidate->title,
                messageIds: [$candidate->firstMessageId],
                likelyFirstVolume: $sniffResult->likelyFirstVolume,
                sourceIndex: $candidate->sourceIndex,
            );
            if ($classification === PayloadClassification::SevenZip && $this->config->processPasswords) {
                $this->processSniffedSevenZip($candidate, $result, $context, $run);
            } elseif ($archiveCandidate->likelyFirstVolume) {
                $this->processCompressedData($payload, $context, false, $archiveCandidate->title);
            } else {
                $run->deferredArchives[] = ['payload' => $payload, 'candidate' => $archiveCandidate];
            }

            return 'found';
        }

        if (in_array($classification, [PayloadClassification::Matroska, PayloadClassification::Mp4, PayloadClassification::Avi], true)) {
            if ($run->firstVideoHandled) {
                $this->processSniffedVideo($payload, $classification, $context);
            } else {
                $run->firstVideoHandled = true;
                $this->processFirstSniffedVideo($candidate, $payload, $classification, $context);
                if ($context->groupUnavailable) {
                    return 'stop';
                }
            }

            return 'found';
        }

        match ($classification) {
            PayloadClassification::Text => $this->processSniffedNfo($payload, $context),
            PayloadClassification::Unknown => null,
        };

        return 'next';
    }

    /**
     * An obfuscated split archive gives its parts random names, and only its
     * first part carries a signature. When the selected candidates found no
     * archive or video, sniff the rest of the split-set files, within their
     * own limits, until one is.
     */
    private function searchSplitSet(ReleaseProcessingContext $context, PayloadSniffRun $run): void
    {
        if (! $this->config->processPasswords || $run->foundContainer || $context->groupUnavailable
            || $context->isTimedOut($this->config->releaseProcessingTimeout)
            || $this->config->payloadSplitSearchMaxFiles <= 0 || $this->config->payloadSplitSearchByteBudget <= 0) {
            return;
        }

        $files = $this->candidateSelector->splitSetFiles($context->nzbContents);
        if ($files === []) {
            return;
        }
        $bytes = array_sum(array_map(static fn (UnknownPayloadCandidate $file): int => $file->estimatedFirstSegmentBytes, $files));
        if (count($files) > $this->config->payloadSplitSearchMaxFiles || $bytes > $this->config->payloadSplitSearchByteBudget) {
            $context->recordSevenZipVolumeSearchCap();

            return;
        }

        foreach ($files as $file) {
            if (isset($run->attempted[$file->firstMessageId])) {
                continue;
            }
            if ($context->isTimedOut($this->config->releaseProcessingTimeout)) {
                // A verdict sends the release through the inspection path's retries
                // instead of the timeout count that deletes it.
                $context->recordPasswordVerdict(Enums\PasswordVerdict::Incomplete, 'seven-zip-volume-search-timeout');

                return;
            }
            $run->searchFiles++;
            $run->searchBytes += $file->estimatedFirstSegmentBytes;
            if ($this->sniffCandidate($file, $context, $run) !== 'next') {
                return;
            }
        }
    }

    /**
     * A sniffed 7z first part. Its start header gives the archive's size, so
     * a set of equal parts and one smaller last part gives the part count and
     * the last part's size; the last part is the eligible file of that size.
     * Its header is read through placeholders for the parts in between.
     *
     * @param  array<string, mixed>  $result  The first part's sniff result.
     */
    private function processSniffedSevenZip(
        UnknownPayloadCandidate $candidate,
        array $result,
        ReleaseProcessingContext $context,
        PayloadSniffRun $run,
    ): void {
        if (isset($context->inspectedSevenZipTitles[$candidate->title])) {
            return;
        }
        $segments = array_values($context->nzbContents[$candidate->sourceIndex]['segments'] ?? []);
        $first = new ArchiveCandidate($candidate->title, [$candidate->firstMessageId], true,
            $candidate->sourceIndex, array_slice($segments, 1));
        $metadata = $result['metadata'] ?? null;
        try {
            $header = SevenZip\Inspector::startHeader((string) $result['data']);
        } catch (\RuntimeException) {
            $header = null;
        }
        if (! $metadata instanceof YencArticleMetadata || $header === null || $metadata->fileSize < 1) {
            $this->processSevenZip($first, $context, true, $run->held);

            return;
        }

        $total = 32 + $header['offset'] + $header['size'];
        $partSize = $metadata->fileSize;
        $parts = intdiv($total, $partSize) + ($total % $partSize > 0 ? 1 : 0);
        if ($parts <= 1) {
            $this->processSevenZip($first, $context, true, $run->held);

            return;
        }

        $lastSize = $total - ($parts - 1) * $partSize;
        $others = array_values(array_filter(
            $this->candidateSelector->eligibleFiles($context->nzbContents),
            static fn (UnknownPayloadCandidate $file): bool => $file->sourceIndex !== $candidate->sourceIndex,
        ));
        if ($lastSize === $partSize || count($others) < $parts - 1) {
            $context->inspectedSevenZipTitles[$candidate->title] = true;
            $context->recordPasswordVerdict(Enums\PasswordVerdict::Incomplete, 'missing-volume');

            return;
        }

        $last = $this->findLastSevenZipPart($others, $lastSize, $context, $run);
        if (is_string($last)) {
            $context->inspectedSevenZipTitles[$candidate->title] = true;
            $context->recordPasswordVerdict(Enums\PasswordVerdict::Incomplete, $last);

            return;
        }
        $lastSegments = array_values($context->nzbContents[$last->sourceIndex]['segments'] ?? []);
        $volumes = [
            $first,
            ...array_fill(0, $parts - 2, new SevenZip\PlaceholderVolume($partSize)),
            new ArchiveCandidate($last->title, [$last->firstMessageId], false, $last->sourceIndex, array_slice($lastSegments, 1)),
        ];
        $this->processSevenZip($first, $context, true, $run->held, $volumes);
    }

    /**
     * The eligible file whose first article says it is the last part's size:
     * a held one first, then files sniffed from the fewest segments up, within
     * the split-archive search's limits. A string is why none was found.
     *
     * @param  list<UnknownPayloadCandidate>  $others
     */
    private function findLastSevenZipPart(
        array $others,
        int $lastSize,
        ReleaseProcessingContext $context,
        PayloadSniffRun $run,
    ): UnknownPayloadCandidate|string {
        $unheld = [];
        foreach ($others as $file) {
            $held = $run->held[$file->firstMessageId] ?? null;
            if ($held === null) {
                // A file whose sniff already failed is not asked for again.
                if (! isset($run->attempted[$file->firstMessageId])) {
                    $unheld[] = $file;
                }
            } elseif (($held['metadata'] ?? null) instanceof YencArticleMetadata && $held['metadata']->fileSize === $lastSize) {
                return $file;
            }
        }
        usort($unheld, static fn (UnknownPayloadCandidate $left, UnknownPayloadCandidate $right): int => $left->segmentCount <=> $right->segmentCount ?: $left->sourceIndex <=> $right->sourceIndex);

        foreach ($unheld as $file) {
            if ($context->isTimedOut($this->config->releaseProcessingTimeout)) {
                return 'seven-zip-volume-search-timeout';
            }
            if ($run->searchFiles >= $this->config->payloadSplitSearchMaxFiles
                || $run->searchBytes + $file->estimatedFirstSegmentBytes > $this->config->payloadSplitSearchByteBudget) {
                return 'missing-volume';
            }
            $run->searchFiles++;
            $run->searchBytes += $file->estimatedFirstSegmentBytes;
            $result = $this->downloadFirstSegment($file, $context, $run);
            if ($result['groupUnavailable']) {
                return 'group-unavailable';
            }
            $metadata = $result['metadata'] ?? null;
            if ($metadata instanceof YencArticleMetadata && $metadata->fileSize === $lastSize) {
                return $file;
            }
        }

        return 'missing-volume';
    }

    /**
     * Downloads a file's first segment once: the attempt is recorded, a
     * successful result is held for the inspection, and an unavailable group
     * is flagged.
     *
     * @return array<string, mixed>
     */
    private function downloadFirstSegment(UnknownPayloadCandidate $file, ReleaseProcessingContext $context, PayloadSniffRun $run): array
    {
        $result = $this->downloadService->download(
            DownloadKind::PayloadSniff,
            [$file->firstMessageId],
            $context->releaseGroupName,
            $context->release->id,
            $file->title,
        );
        $run->attempted[$file->firstMessageId] = true;
        if ($result['groupUnavailable']) {
            $context->groupUnavailable = true;
            $this->output->echoGroupUnavailable();
        } elseif ($result['success'] && is_string($result['data'])) {
            $run->held[$file->firstMessageId] = $result;
        }

        return $result;
    }

    private function processSniffedPar2(string $payload, ReleaseProcessingContext $context): void
    {
        if ($context->foundPAR2Info && ! $context->purePar2Sidecar) {
            return;
        }

        $path = $context->tmpPath.'sniffed_'.uniqid('', true).'.par2';
        File::put($path, $payload);
        try {
            $this->releaseManager->processPar2File($path, $context, $this->archiveService->getPar2Info());
        } finally {
            File::delete($path);
        }
    }

    /**
     * The first sniffed video follows the named-file flow: it fetches the rest
     * of the head window, the sniffed article being its first segment. When
     * there is no rest, or it cannot be fetched, the held article stands alone.
     */
    private function processFirstSniffedVideo(
        UnknownPayloadCandidate $candidate,
        string $payload,
        PayloadClassification $classification,
        ReleaseProcessingContext $context,
    ): void {
        if ($context->foundMediaInfo && $context->foundSample && $context->foundVideo) {
            $this->processSniffedVideo($payload, $classification, $context);

            return;
        }

        $context->workPlan = $this->workPlanner->withMediaInfoFile(
            $context->workPlan,
            $context->nzbContents[$candidate->sourceIndex],
        );
        $context->mediaInfoMessageIDs = $context->workPlan->mediaInfoMessageIds;

        $remainingIds = array_values(array_diff($context->mediaInfoMessageIDs, [$candidate->firstMessageId]));
        if ($remainingIds === []) {
            $this->processSniffedVideo($payload, $classification, $context);

            return;
        }

        $result = $this->downloadService->download(
            DownloadKind::MediaInfo,
            $remainingIds,
            $context->releaseGroupName,
            $context->release->id,
        );
        if ($result['groupUnavailable']) {
            $context->groupUnavailable = true;
            $this->output->echoGroupUnavailable();

            return;
        }

        if ($result['success'] && is_string($result['data'])
            && $this->downloadService->meetsMinimumSize($payload.$result['data'])
        ) {
            $this->processMainVideoHead($payload.$result['data'], $context);

            return;
        }

        $this->processSniffedVideo($payload, $classification, $context);
    }

    private function processSniffedVideo(
        string $payload,
        PayloadClassification $classification,
        ReleaseProcessingContext $context,
    ): void {
        $extension = $classification->mediaExtension() ?? 'bin';
        $path = $context->tmpPath.'sniffed_'.uniqid('', true).'.'.$extension;
        File::put($path, $payload);
        try {
            $this->mediaService->processVideoFile(
                $path,
                $context,
                $context->tmpPath,
                $this->mediaInfoContext(basename($path), MediaInfoSourceCompleteness::Partial),
            );
        } finally {
            File::delete($path);
        }
    }

    private function processSniffedNfo(string $payload, ReleaseProcessingContext $context): void
    {
        if (! $context->releaseHasNoNFO) {
            return;
        }

        $path = $context->tmpPath.'sniffed_'.uniqid('', true).'.nfo';
        File::put($path, $payload);
        try {
            if ($this->releaseManager->processNfoFile($path, $context, $this->downloadService->getNNTP())) {
                $this->output->echoNfoFound();
            }
        } finally {
            File::delete($path);
        }
    }

    private function discardedResult(ReleaseProcessingContext $context): ?ReleaseProcessingResult
    {
        if (! $context->releaseDiscarded) {
            return null;
        }

        return $this->result(
            $context,
            ProcessingOutcome::Discarded,
            reason: 'Release contained an executable file and was discarded.',
        );
    }

    private function processMessageIdDownloads(ReleaseProcessingContext $context): void
    {
        if ((! $context->foundSample || ! $context->foundVideo) && $context->sampleMessageIDs !== []) {
            $result = $this->downloadService->download(
                DownloadKind::Sample,
                $context->sampleMessageIDs,
                $context->releaseGroupName,
                $context->release->id
            );

            if ($result['success'] && is_string($result['data']) && $this->downloadService->meetsMinimumSize($result['data'])) {
                $this->output->echoSampleDownload();
                $fileLocation = $context->tmpPath.'sample_'.random_int(0, 99999).'.avi';
                File::put($fileLocation, $result['data']);

                if (! $context->foundSample && $this->mediaService->getSample($fileLocation, $context->tmpPath, $context->release->guid)) {
                    $context->markFound('sample');
                    $this->output->echoSampleCreated();
                }
                if (! $context->foundVideo && $this->mediaService->getVideo($fileLocation, $context->tmpPath, $context->release->guid, (int) $context->release->categories_id)) {
                    $context->markFound('video');
                    $this->output->echoVideoCreated();
                }
            } elseif (! $result['success']) {
                $this->output->echoSampleFailure();
            }
        }

        if ((! $context->foundMediaInfo || ! $context->foundSample || ! $context->foundVideo)
            && ! empty($context->mediaInfoMessageIDs)
        ) {
            $result = $this->downloadService->download(
                DownloadKind::MediaInfo,
                $context->mediaInfoMessageIDs,
                $context->releaseGroupName,
                $context->release->id
            );

            if ($result['success'] && is_string($result['data']) && $this->downloadService->meetsMinimumSize($result['data'])) {
                $this->processMainVideoHead($result['data'], $context);
            } elseif (! $result['success']) {
                $this->output->echoMediaInfoFailure();
            }
        }

        if (! $context->foundJPGSample && $context->jpgMessageIDs !== []) {
            $result = $this->downloadService->download(
                DownloadKind::Jpg,
                $context->jpgMessageIDs,
                $context->releaseGroupName,
                $context->release->id
            );

            if ($result['success'] && is_string($result['data'])) {
                $this->output->echoJpgDownload();
                $fileLocation = $context->tmpPath.'samplepicture.jpg';
                File::put($fileLocation, $result['data']);

                if ($this->mediaService->isValidImage($fileLocation)
                    && $this->mediaService->getJPGSample($fileLocation, $context->release->guid)
                ) {
                    $context->markFound('jpgSample');
                    $this->output->echoJpgSaved();
                }

                File::delete($fileLocation);
            } elseif (! $result['success']) {
                $this->output->echoJpgFailure();
            }
        }
    }

    /**
     * The named-file flow once a main video's head window is on hand: splice a
     * tail moov, top up the dynamic budget, then produce the artifacts.
     */
    private function processMainVideoHead(string $headData, ReleaseProcessingContext $context): void
    {
        $this->output->echoMediaInfoDownload();
        $needsMoovSplice = $this->config->mp4TailFetch
            && $this->payloadSniffer->classify($headData)->classification === PayloadClassification::Mp4
            && $this->mp4MoovSplicer->needsTail($headData);
        $fileLocation = $context->tmpPath.'media.avi';
        File::put($fileLocation, $headData);
        $splice = $needsMoovSplice ? $this->spliceMp4Tail($headData, $context) : null;
        if ($splice !== null) {
            $fileLocation = $context->tmpPath.'media.mp4';
            File::put($fileLocation, $splice['data']);
        }

        // Dynamic segment budget: for a bare non-faststart MP4 the
        // duration is unknowable before the moov splice, so a failed
        // splice means no top-up. After a splice the tail window is
        // already on disk, so the top-up must stop before it.
        if (! $needsMoovSplice || $splice !== null) {
            $fetchedTailIds = $splice !== null && $context->workPlan !== null
                ? $context->workPlan->expandedMediaInfoTailMessageIds($this->config->mp4TailMaxSegments)
                : [];
            $topUpData = $this->dynamicBudgetTopUp($context, $fileLocation, $headData, $fetchedTailIds);
            if ($topUpData !== null) {
                if ($splice !== null) {
                    $merged = $this->mp4MoovSplicer->splice($headData.$topUpData, $splice['moov'], true);
                    if ($merged->status === Mp4MoovSpliceStatus::Spliced && $merged->data !== null) {
                        File::put($fileLocation, $merged->data);
                    }
                } else {
                    File::put($fileLocation, $headData.$topUpData);
                }
            }
        }

        if (! $context->foundMediaInfo && $this->mediaService->getMediaInfo(
            $fileLocation,
            $context->release->id,
            $this->mediaInfoContext(
                $context->workPlan?->mediaInfoSourceFilename,
                MediaInfoSourceCompleteness::Partial,
            ),
        )) {
            $context->markFound('mediaInfo');
            $this->output->echoMediaInfoAdded();
        }
        if (! $context->foundSample && $this->mediaService->getSample($fileLocation, $context->tmpPath, $context->release->guid)) {
            $context->markFound('sample');
            $this->output->echoSampleCreated();
        }
        if (! $context->foundVideo && $this->mediaService->getVideo($fileLocation, $context->tmpPath, $context->release->guid, (int) $context->release->categories_id)) {
            $context->markFound('video');
            $this->output->echoVideoCreated();
        }
    }

    /**
     * Fetch the MP4 tail and splice the moov onto the head. The caller checks
     * that the head is an MP4 whose moov lives at the end of the file.
     *
     * @return array{data: string, moov: string}|null The spliced file plus the bare moov atom for re-splicing after a dynamic top-up.
     */
    private function spliceMp4Tail(string $head, ReleaseProcessingContext $context): ?array
    {
        $plan = $context->workPlan;
        if ($plan === null || $plan->mediaInfoTailMessageIds === []) {
            $context->recordMp4MoovMissing();
            $this->output->echoMp4MoovMissing();

            return null;
        }

        // Contiguity gate: a tail window with gaps — or one that never
        // reaches the declared end of the file — can never contain a usable
        // moov, so skip the fetches entirely and record the distinct reason.
        if (! $plan->mediaInfoTailContiguous) {
            $context->recordSegmentGapSkip();
            $this->output->echoSegmentGapSkip();

            return null;
        }

        $maximumSegments = min(
            $this->config->mp4TailMaxSegments,
            count($plan->expandedMediaInfoTailMessageIds($this->config->mp4TailMaxSegments)),
        );
        $requestedIds = $plan->mediaInfoTailMessageIds;
        $tail = '';
        $downloadedSegmentCount = 0;

        while ($requestedIds !== []) {
            $download = $this->downloadService->download(
                DownloadKind::MediaInfoTail,
                $requestedIds,
                $context->releaseGroupName,
                $context->release->id,
            );
            if (! $download['success'] || ! is_string($download['data'])) {
                $context->recordMp4MoovMissing();
                $this->output->echoMp4MoovMissing();

                return null;
            }

            $tail = $download['data'].$tail;
            $downloadedSegmentCount += count($requestedIds);
            $context->recordMp4TailFetch(strlen($download['data']));
            $this->output->echoMp4TailFetched(strlen($download['data']));
            $atSegmentCap = $downloadedSegmentCount >= $maximumSegments;
            $splice = $this->mp4MoovSplicer->splice($head, $tail, $atSegmentCap);

            if ($splice->status === Mp4MoovSpliceStatus::Spliced && $splice->data !== null) {
                $context->recordMp4MoovFound();
                $this->output->echoMp4MoovFound();

                // rewriteMdatSize() preserves the head's length, so the moov
                // is exactly the bytes appended after it.
                return [
                    'data' => $splice->data,
                    'moov' => substr($splice->data, strlen($head)),
                ];
            }
            if ($splice->status === Mp4MoovSpliceStatus::Missing) {
                $context->recordMp4MoovMissing();
                $this->output->echoMp4MoovMissing();

                return null;
            }

            $nextSegmentCount = min(
                $downloadedSegmentCount + max($this->config->segmentsToDownload, 1),
                $maximumSegments,
            );
            $expandedIds = $plan->expandedMediaInfoTailMessageIds($nextSegmentCount);
            $additionalCount = $nextSegmentCount - $downloadedSegmentCount;
            $requestedIds = array_slice($expandedIds, 0, $additionalCount);
        }

        $context->recordMp4MoovMissing();
        $this->output->echoMp4MoovMissing();

        return null;
    }

    /**
     * Dynamic segment budget (see GLOSSARY.md): when the head stops inside a
     * fast-start moov, first fetch the rest of the moov. Then probe for the
     * overall bitrate and fetch just enough additional head segments to reach
     * the target duration, under the hard byte ceiling covering the total
     * fetched for this file. Returns every fetched top-up byte, or null when
     * the fixed-count behavior stands (toggle off, unknown bitrate, target
     * already met, ceiling reached, or segment gaps).
     *
     * @param  list<string>  $tailWindowMessageIds  Segment ids already fetched for the MP4 tail; the top-up pool stops before the first of them so no byte is fetched twice.
     */
    private function dynamicBudgetTopUp(
        ReleaseProcessingContext $context,
        string $probeFileLocation,
        string $headData,
        array $tailWindowMessageIds = [],
    ): ?string {
        $plan = $context->workPlan;
        if ($plan === null
            || $this->config->previewTargetSeconds <= 0
            || ! $this->dynamicBudgetPolicy->enabledForCategory((int) $context->release->categories_id)
        ) {
            return null;
        }

        $expansionPool = $plan->mediaInfoExpansionMessageIds;
        if ($tailWindowMessageIds !== []) {
            $fetchedTail = array_flip($tailWindowMessageIds);
            $prefix = [];
            foreach ($expansionPool as $messageId) {
                if (isset($fetchedTail[$messageId])) {
                    break;
                }
                $prefix[] = $messageId;
            }
            $expansionPool = $prefix;
        }
        if ($expansionPool === []) {
            return null;
        }

        $moovEnd = $this->mp4MoovSplicer->moovEndOffset($headData);
        $moovData = '';
        $segmentsHeld = count($context->mediaInfoMessageIDs);
        if ($moovEnd !== null && $moovEnd > strlen($headData)) {
            if (($this->config->previewMaxFetchBytes > 0 && $moovEnd > $this->config->previewMaxFetchBytes)
                || ($plan->mediaInfoFileSizeBytes > 0 && $moovEnd > $plan->mediaInfoFileSizeBytes)
            ) {
                return null;
            }

            $moovFetch = $this->fetchHeadToMoovEnd($context, $plan, $headData, $moovEnd, $expansionPool);
            $moovData = $moovFetch['data'];
            if (strlen($headData.$moovData) < $moovEnd) {
                return $moovData === '' ? null : $moovData;
            }

            File::put($probeFileLocation, $headData.$moovData);
            $segmentsHeld += $moovFetch['segments'];
            $expansionPool = array_slice($expansionPool, $moovFetch['segments']);
        }

        $topUpData = $moovData.$this->fetchHeadToTargetDuration(
            $context,
            $plan,
            $probeFileLocation,
            $headData.$moovData,
            $segmentsHeld,
            $moovEnd,
            $expansionPool,
        );

        return $topUpData === '' ? null : $topUpData;
    }

    /**
     * The moov phase of the dynamic budget: fetch until the head holds the
     * whole fast-start moov. The data falls short of the moov end when a bound
     * stopped the phase; ffmpeg can read nothing from the file before that.
     *
     * @param  list<string>  $expansionPool
     * @return array{data: string, segments: int}
     */
    private function fetchHeadToMoovEnd(
        ReleaseProcessingContext $context,
        AdditionalWorkPlan $plan,
        string $headData,
        int $moovEnd,
        array $expansionPool,
    ): array {
        $headSegmentCount = count($context->mediaInfoMessageIDs);
        $perSegmentBytes = $headSegmentCount > 0 ? intdiv(strlen($headData), $headSegmentCount) : 0;
        $fetched = '';
        $fetchedSegments = 0;

        while ($perSegmentBytes > 0 && ($bytesHeld = strlen($headData) + strlen($fetched)) < $moovEnd) {
            $segmentsByCeiling = $this->config->previewMaxFetchBytes > 0
                ? intdiv(max($this->config->previewMaxFetchBytes - $bytesHeld, 0), $perSegmentBytes)
                : PHP_INT_MAX;
            $segmentCount = min(
                (int) ceil(($moovEnd - $bytesHeld) / $perSegmentBytes),
                $segmentsByCeiling,
                count($expansionPool) - $fetchedSegments,
            );
            if ($segmentCount <= 0) {
                break;
            }

            if ($headSegmentCount + $fetchedSegments + $segmentCount > $plan->mediaInfoContiguousHeadSegments) {
                $context->recordSegmentGapSkip();
                $this->output->echoSegmentGapSkip();

                break;
            }

            $download = $this->downloadService->download(
                DownloadKind::MediaInfoTopUp,
                array_slice($expansionPool, $fetchedSegments, $segmentCount),
                $context->releaseGroupName,
                $context->release->id,
            );
            if ($download['groupUnavailable']) {
                $context->groupUnavailable = true;
                $this->output->echoGroupUnavailable();

                break;
            }
            if (! $download['success'] || ! is_string($download['data'])) {
                break;
            }

            $fetched .= $download['data'];
            $fetchedSegments += $segmentCount;
            $this->output->echoDynamicTopUpFetched(strlen($download['data']));
        }

        return ['data' => $fetched, 'segments' => $fetchedSegments];
    }

    /**
     * The duration phase of the dynamic budget: probe the file for the overall
     * bitrate and fetch the head segments the target duration still needs.
     * Playable bytes count from the end of a fast-start moov.
     *
     * @param  list<string>  $expansionPool
     * @return string The bytes fetched; empty when the bytes held stand.
     */
    private function fetchHeadToTargetDuration(
        ReleaseProcessingContext $context,
        AdditionalWorkPlan $plan,
        string $probeFileLocation,
        string $heldData,
        int $segmentsHeld,
        ?int $moovEnd,
        array $expansionPool,
    ): string {
        if ($expansionPool === []) {
            return '';
        }

        $bytesPerSecond = $this->headProbe
            ->probe($probeFileLocation, $this->config)
            ?->bytesPerSecond($plan->mediaInfoFileSizeBytes);
        if ($bytesPerSecond === null || $bytesPerSecond <= 0) {
            // Unknown bitrate: keep the fixed-count behavior rather than guess.
            return '';
        }

        $perSegmentBytes = $segmentsHeld > 0 ? intdiv(strlen($heldData), $segmentsHeld) : 0;
        if ($perSegmentBytes <= 0) {
            return '';
        }

        $neededBytes = (int) ceil($bytesPerSecond * $this->config->previewTargetSeconds)
            + ($moovEnd ?? 0)
            - strlen($heldData);
        if ($neededBytes <= 0) {
            return '';
        }

        $alreadyFetchedBytes = strlen($heldData) + $context->mp4TailMetrics->tailBytes;
        $segmentsByTarget = (int) ceil($neededBytes / $perSegmentBytes);
        $segmentsByCeiling = $this->config->previewMaxFetchBytes > 0
            ? intdiv(max($this->config->previewMaxFetchBytes - $alreadyFetchedBytes, 0), $perSegmentBytes)
            : PHP_INT_MAX;
        $segmentCount = min($segmentsByTarget, $segmentsByCeiling, count($expansionPool));
        if ($segmentCount <= 0) {
            return '';
        }

        // Contiguity gate: gaps inside the needed head range make the top-up
        // pointless — the decoder stops at the first missing byte.
        if ($segmentsHeld + $segmentCount > $plan->mediaInfoContiguousHeadSegments) {
            $context->recordSegmentGapSkip();
            $this->output->echoSegmentGapSkip();

            return '';
        }

        $download = $this->downloadService->download(
            DownloadKind::MediaInfoTopUp,
            array_slice($expansionPool, 0, $segmentCount),
            $context->releaseGroupName,
            $context->release->id,
        );
        if ($download['groupUnavailable']) {
            $context->groupUnavailable = true;
            $this->output->echoGroupUnavailable();

            return '';
        }
        if (! $download['success'] || ! is_string($download['data'])) {
            return '';
        }

        $this->output->echoDynamicTopUpFetched(strlen($download['data']));

        return $download['data'];
    }

    /**
     * @param  list<string>  $triedCompressedMids
     */
    private function processNzbCompressedFiles(
        ReleaseProcessingContext $context,
        bool $reverse,
        array &$triedCompressedMids
    ): void {
        if ($this->config->processPasswords && ! $reverse) {
            foreach ($context->workPlan->archiveCandidates ?? [] as $candidate) {
                if (PostedFileClassifier::isSevenZip($candidate->title)) {
                    if ($context->groupUnavailable) {
                        $context->recordPasswordVerdict(Enums\PasswordVerdict::Incomplete, 'group-unavailable');
                    } else {
                        $this->processSevenZip($candidate, $context);
                    }
                    if ($context->releaseHasPassword || $context->releaseDiscarded) {
                        return;
                    }
                }
            }
        }
        if ($context->groupUnavailable) {
            return;
        }

        $archiveCandidates = match (true) {
            $context->workPlan === null => [],
            $reverse => $context->workPlan->orderedArchiveCandidates(true),
            default => $context->workPlan->prioritizedArchiveCandidates(),
        };
        if (! $reverse) {
            $triedCompressedMids = [];
        }

        $failed = $downloaded = 0;
        $topUpSpent = false;
        $moovTail = null;

        foreach ($archiveCandidates as $archiveCandidate) {
            if ($downloaded >= $this->config->maximumRarSegments
                || $failed >= $this->config->maximumRarPasswordChecks
                || $context->releaseHasPassword
            ) {
                break;
            }

            if ($reverse && array_intersect($archiveCandidate->messageIds, $triedCompressedMids) !== []) {
                continue;
            }

            if (! $reverse) {
                $triedCompressedMids = [...$triedCompressedMids, ...$archiveCandidate->messageIds];
            }

            if ($this->config->processPasswords && PostedFileClassifier::isSevenZip($archiveCandidate->title)) {
                continue;
            }

            $result = $this->downloadService->download(
                DownloadKind::Compressed,
                $archiveCandidate->messageIds,
                $context->releaseGroupName,
                $context->release->id,
                $archiveCandidate->title,
            );

            if ($result['groupUnavailable']) {
                $context->groupUnavailable = true;
                $this->output->echoGroupUnavailable();
                break;
            }

            if ($result['success'] && is_string($result['data'])) {
                $this->output->echoCompressedDownload();
                $downloaded++;

                $processed = $this->processCompressedData(
                    $result['data'],
                    $context,
                    $reverse,
                    $archiveCandidate->title,
                );
                if (! $reverse) {
                    $moovTail ??= $this->recoverArchiveMoovTail($context, $archiveCandidate, $result['data']);
                }
                if (! $reverse && ! $topUpSpent) {
                    $topUpSpent = $this->compressedDynamicTopUp(
                        $context,
                        $archiveCandidate,
                        $result['data'],
                        $triedCompressedMids,
                        $moovTail,
                    );
                }
                if ($processed) {
                    break;
                }
            } else {
                $failed++;
                $this->output->echoCompressedFailure($failed);
            }
        }
    }

    /**
     * Moov tail recovery for an MP4 stored in a RAR set (see GLOSSARY.md). This
     * runs with the dynamic budget on or off, so it extracts through a working
     * volume of its own, which must not survive into the extracted-files sweep.
     * A splice overwrites the extracted fragment; any failure leaves it as it was.
     *
     * @return ArchiveMoovTail|null Null while the candidate lists no video, leaving later candidates free to try.
     */
    private function recoverArchiveMoovTail(
        ReleaseProcessingContext $context,
        ArchiveCandidate $anchor,
        string $anchorHead,
    ): ?ArchiveMoovTail {
        $plan = $context->workPlan;
        if ($plan === null
            || ! $this->config->mp4TailFetch
            || ($context->foundMediaInfo && $context->foundSample && $context->foundVideo)
            || $context->releaseHasPassword
        ) {
            return null;
        }

        $videoEntry = $this->leadingArchiveVideoEntry($anchorHead);
        if ($videoEntry === null) {
            return null;
        }
        if ($videoEntry['compressed'] !== 0 || $videoEntry['pass'] !== 0 || ! ArchivePartTail::locatesVideo($videoEntry)) {
            return new ArchiveMoovTail;
        }

        $workingVolume = $this->previewArchiveVolumePath(
            $context->tmpPath,
            1,
            $this->usesOldVolumeNumbers($anchorHead),
        );
        try {
            File::put($workingVolume, $anchorHead);
            $fragmentPath = $this->extractPreviewFragment($workingVolume, $videoEntry['name'], $context);
        } finally {
            File::delete($workingVolume);
        }
        if ($fragmentPath === null) {
            return new ArchiveMoovTail;
        }

        $fragment = File::get($fragmentPath);
        if ($this->payloadSniffer->classify($fragment)->classification !== PayloadClassification::Mp4
            || ! $this->mp4MoovSplicer->needsTail($fragment)
        ) {
            return new ArchiveMoovTail;
        }

        $fetcher = new ArchiveMoovTailFetcher(
            $this->config,
            $this->downloadService,
            $this->archiveService,
            $this->mp4MoovSplicer,
            $this->output,
            $context,
            $fragment,
            $videoEntry['size'],
        );
        $moovTail = $fetcher->fetch($plan, $anchor, new ArchivePartTail(
            $anchor,
            $anchorHead,
            count($anchor->messageIds),
            $videoEntry,
        ));
        if ($moovTail->splicedData !== null) {
            File::put($fragmentPath, $moovTail->splicedData);
        }

        return $moovTail;
    }

    /**
     * Dynamic segment budget for archive-wrapped video (see GLOSSARY.md):
     * after the fixed head fetch of an archive part has been extracted, probe
     * the partial video fragment for bitrate and keep fetching further
     * archive segments — the rest of this part, then subsequent parts in
     * posted order — re-extracting after each fetch until the fragment covers
     * the target duration or a bound hits: the byte ceiling, the part
     * ceiling, no next part, or extraction that stops progressing (a
     * compressed-mode archive a partial fetch cannot extend). A fast-start
     * moov the fragment stops inside is fetched first, under the same bounds,
     * because nothing can be probed before it is whole.
     *
     * @param  list<string>  $triedCompressedMids
     * @return bool Whether this candidate carried a video entry and the budget was spent on it; false leaves later candidates free to try.
     */
    private function compressedDynamicTopUp(
        ReleaseProcessingContext $context,
        ArchiveCandidate $anchor,
        string $initialData,
        array $triedCompressedMids,
        ?ArchiveMoovTail $moovTail,
    ): bool {
        $plan = $context->workPlan;
        if ($plan === null
            || $context->foundVideo
            || $context->releaseHasPassword
            || $context->groupUnavailable
            || $this->config->previewTargetSeconds <= 0
            || ! $this->dynamicBudgetPolicy->enabledForCategory((int) $context->release->categories_id)
        ) {
            return false;
        }

        $videoEntry = $this->leadingArchiveVideoEntry($initialData);
        if ($videoEntry === null) {
            return false;
        }

        $perSegmentBytes = $anchor->messageIds === []
            ? 0
            : intdiv(strlen($initialData), count($anchor->messageIds));
        if ($perSegmentBytes <= 0) {
            return true;
        }

        $oldVolumeNumbers = $this->usesOldVolumeNumbers($initialData);
        $firstVolumePath = $this->previewArchiveVolumePath($context->tmpPath, 1, $oldVolumeNumbers);

        try {
            File::put($firstVolumePath, $initialData);

            $fragmentPath = $this->extractPreviewFragment($firstVolumePath, $videoEntry['name'], $context);
            if ($fragmentPath === null) {
                $this->logCompressedExtractionFailed($context, 'initial extraction produced no fragment');

                return true;
            }

            // The extraction overwrote any splice, so it counts as a growth.
            $fragmentBytes = (int) File::size($fragmentPath);
            $this->respliceMoov($fragmentPath, $moovTail?->moov);

            $fetchedMessageIds = array_fill_keys($triedCompressedMids, true);
            $tailMessageIds = array_flip($moovTail->fetchedMessageIds ?? []);
            $fetch = new CompressedTopUpFetch(
                anchor: $anchor,
                videoEntryName: $videoEntry['name'],
                perSegmentBytes: $perSegmentBytes,
                fetchedMessageIds: $fetchedMessageIds,
                pendingSegments: $this->segmentsBeforeTail(
                    $this->unfetchedMessageIds($anchor->expansionMessageIds, $fetchedMessageIds),
                    $tailMessageIds,
                ),
                nextParts: $this->partsBeforeTail($plan->archivePartsAfter($anchor), $tailMessageIds),
                firstVolumePath: $firstVolumePath,
                oldVolumeNumbers: $oldVolumeNumbers,
                anchorSegmentsHeld: count($anchor->messageIds),
                fetchedBytes: strlen($initialData) + $context->mp4TailMetrics->tailBytes,
                fragmentPath: $fragmentPath,
                fragmentBytes: $fragmentBytes,
                splicedMoov: $moovTail?->moov,
            );

            $moovEnd = $this->mp4MoovSplicer->moovEndOffset(File::get($fragmentPath));
            if ($moovEnd !== null && $moovEnd > $fetch->fragmentBytes) {
                if ($this->config->previewMaxFetchBytes > 0 && $moovEnd > $this->config->previewMaxFetchBytes) {
                    $this->logCompressedProbeFailed($context, $fragmentPath, 'moov past byte ceiling');

                    return true;
                }
                if ($videoEntry['size'] > 0 && $moovEnd > $videoEntry['size']) {
                    $this->logCompressedProbeFailed($context, $fragmentPath, 'moov past file end');

                    return true;
                }
                if (! $this->fetchCompressedMoov($context, $moovEnd, $fetch)) {
                    return true;
                }
            }

            $decodableSeconds = $this->decodableLengthProbe->demuxedSeconds($fetch->fragmentPath, $this->config);
            if ($decodableSeconds !== null && $decodableSeconds >= $this->config->previewTargetSeconds) {
                $this->logCompressedTargetMet($context, $decodableSeconds);

                return true;
            }

            $bytesPerSecond = $this->headProbe
                ->probe($fetch->fragmentPath, $this->config)
                ?->bytesPerSecond($videoEntry['size']);
            if ($bytesPerSecond === null || $bytesPerSecond <= 0) {
                // Unknown bitrate: keep the fixed-fetch behavior rather than guess.
                $this->logCompressedProbeFailed(
                    $context,
                    $fetch->fragmentPath,
                    $decodableSeconds === null ? 'duration unavailable' : 'bitrate unavailable',
                );

                return true;
            }

            // A moov with no mdat bytes behind it demuxes nothing yet; the
            // known bitrate still says how much more to fetch.
            $decodableSeconds ??= 0.0;

            if ($this->skipForAnchorGap(
                $context,
                $fetch,
                $this->segmentsForRemainingDuration($decodableSeconds, $bytesPerSecond, $perSegmentBytes),
            )) {
                return true;
            }

            $this->fetchCompressedTopUp($context, $bytesPerSecond, $decodableSeconds, $fetch);
        } finally {
            // The working volume files must not survive into the
            // extracted-files sweep, which would treat them as freshly
            // extracted nested archives.
            foreach (File::glob($context->tmpPath.'preview-archive.*') as $volumePath) {
                File::delete($volumePath);
            }
        }

        return true;
    }

    /**
     * Fetch until the fragment holds the whole fast-start moov: ffmpeg can
     * neither read a duration nor a bitrate before that.
     *
     * @return bool Whether the moov is covered; a bound that stops the phase leaves the fragment on disk.
     */
    private function fetchCompressedMoov(
        ReleaseProcessingContext $context,
        int $moovEnd,
        CompressedTopUpFetch $fetch,
    ): bool {
        while ($fetch->fragmentBytes < $moovEnd) {
            $segmentsWanted = (int) ceil(($moovEnd - $fetch->fragmentBytes) / $fetch->perSegmentBytes);
            if ($this->skipForAnchorGap($context, $fetch, $segmentsWanted)
                || ! $this->growCompressedFragment($context, $segmentsWanted, $fetch)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * The duration phase of the compressed dynamic budget; every bound that
     * stops it leaves the longest fragment extraction could produce on disk
     * for the extracted-files sweep to clip.
     */
    private function fetchCompressedTopUp(
        ReleaseProcessingContext $context,
        float $bytesPerSecond,
        float $decodableSeconds,
        CompressedTopUpFetch $fetch,
    ): void {
        while ($decodableSeconds < $this->config->previewTargetSeconds) {
            $segmentsWanted = $this->segmentsForRemainingDuration(
                $decodableSeconds,
                $bytesPerSecond,
                $fetch->perSegmentBytes,
            );
            if (! $this->growCompressedFragment($context, $segmentsWanted, $fetch)) {
                break;
            }

            $probedSeconds = $this->decodableLengthProbe->demuxedSeconds($fetch->fragmentPath, $this->config);
            if ($probedSeconds === null) {
                $this->logCompressedProbeFailed($context, $fetch->fragmentPath, 'duration unavailable');
                break;
            }
            $decodableSeconds = $probedSeconds;
            if ($decodableSeconds >= $this->config->previewTargetSeconds) {
                $this->logCompressedTargetMet($context, $decodableSeconds);
            }
        }
    }

    /**
     * One fetch-and-extract step, in the order every phase shares: the byte
     * ceiling, then the rest of the anchor part, then later parts under the
     * part ceiling and the gap gate, then the no-progress stop.
     *
     * @return bool Whether the fragment grew; false means a bound stopped the fetch.
     */
    private function growCompressedFragment(
        ReleaseProcessingContext $context,
        int $segmentsWanted,
        CompressedTopUpFetch $fetch,
    ): bool {
        if ($this->config->previewMaxFetchBytes > 0) {
            $segmentsWanted = min(
                $segmentsWanted,
                intdiv(max($this->config->previewMaxFetchBytes - $fetch->fetchedBytes, 0), $fetch->perSegmentBytes),
            );
            if ($segmentsWanted <= 0) {
                return false;
            }
        }

        while ($fetch->pendingSegments === []) {
            if ($fetch->nextParts === [] || $fetch->partsUsed >= $this->config->previewMaxRarParts) {
                return false;
            }
            $part = array_shift($fetch->nextParts);
            $allPartSegments = [...$part->messageIds, ...$part->expansionMessageIds];
            $partSegments = $this->unfetchedMessageIds($allPartSegments, $fetch->fetchedMessageIds);
            $usableSegments = $this->unfetchedMessageIds(
                array_slice($allPartSegments, 0, max($part->contiguousHeadSegments, 0)),
                $fetch->fetchedMessageIds,
            );
            if (min($segmentsWanted, count($partSegments)) > count($usableSegments)) {
                // A numbering gap sits inside the range this part would
                // need to contribute (#290 semantics): bytes past it can
                // never be contiguous, so keep what extraction yielded.
                $context->recordSegmentGapSkip();
                $this->output->echoSegmentGapSkip();

                return false;
            }
            if (count($usableSegments) < count($partSegments)) {
                // A gap after the needed range: this part's contiguous
                // head is still usable, but nothing beyond it can be.
                $fetch->nextParts = [];
            }
            $fetch->partsUsed++;
            $fetch->currentVolume++;
            $fetch->currentVolumePath = $this->previewArchiveVolumePath(
                $context->tmpPath,
                $fetch->currentVolume,
                $fetch->oldVolumeNumbers,
            );
            File::put($fetch->currentVolumePath, '');
            $fetch->pendingSegments = $usableSegments;
        }

        $batch = array_splice($fetch->pendingSegments, 0, min($segmentsWanted, count($fetch->pendingSegments)));
        foreach ($batch as $messageId) {
            $fetch->fetchedMessageIds[$messageId] = true;
        }
        $download = $this->downloadService->download(
            DownloadKind::CompressedTopUp,
            $batch,
            $context->releaseGroupName,
            $context->release->id,
            $fetch->anchor->title,
        );
        if ($download['groupUnavailable']) {
            $context->groupUnavailable = true;
            $this->output->echoGroupUnavailable();

            return false;
        }
        if (! $download['success'] || ! is_string($download['data'])) {
            return false;
        }

        if ($fetch->currentVolume === 1) {
            $fetch->anchorSegmentsHeld += count($batch);
        }
        File::append($fetch->currentVolumePath, $download['data']);
        $fetch->fetchedBytes += strlen($download['data']);
        $this->output->echoDynamicTopUpFetched(strlen($download['data']));

        $grownPath = $this->extractPreviewFragment($fetch->firstVolumePath, $fetch->videoEntryName, $context);
        if ($grownPath === null) {
            $this->logCompressedExtractionFailed($context, 'top-up extraction produced no fragment');

            return false;
        }

        $grownBytes = (int) File::size($grownPath);
        $this->respliceMoov($grownPath, $fetch->splicedMoov);
        if ($grownBytes <= $fetch->fragmentBytes) {
            $this->logCompressedExtractionFailed($context, 'top-up extraction made no progress', $grownBytes);

            return false;
        }
        $fetch->fragmentPath = $grownPath;
        $fetch->fragmentBytes = $grownBytes;

        return true;
    }

    /**
     * Put a recovered moov back on a fragment extraction just overwrote.
     */
    private function respliceMoov(string $fragmentPath, ?string $moov): void
    {
        if ($moov === null) {
            return;
        }

        $merged = $this->mp4MoovSplicer->splice(File::get($fragmentPath), $moov, true);
        if ($merged->status === Mp4MoovSpliceStatus::Spliced && $merged->data !== null) {
            File::put($fragmentPath, $merged->data);
        }
    }

    /**
     * The tail bytes stay out of the working volumes, so the top-up pool stops
     * before the first segment fetched for the tail: no segment is fetched
     * twice, and no volume is left with a hole in it.
     *
     * @param  list<string>  $messageIds
     * @param  array<string, int>  $tailMessageIds
     * @return list<string>
     */
    private function segmentsBeforeTail(array $messageIds, array $tailMessageIds): array
    {
        $before = [];
        foreach ($messageIds as $messageId) {
            if (isset($tailMessageIds[$messageId])) {
                break;
            }
            $before[] = $messageId;
        }

        return $before;
    }

    /**
     * @param  list<ArchiveCandidate>  $parts
     * @param  array<string, int>  $tailMessageIds
     * @return list<ArchiveCandidate>
     */
    private function partsBeforeTail(array $parts, array $tailMessageIds): array
    {
        $before = [];
        foreach ($parts as $part) {
            if (array_intersect_key(array_flip($part->segmentIds()), $tailMessageIds) !== []) {
                break;
            }
            $before[] = $part;
        }

        return $before;
    }

    /**
     * Contiguity gate: a numbering gap inside the range the anchor part would
     * contribute makes every fetch provably pointless — the archive stream
     * stops extending at the first missing byte. Records the skip when it hits.
     */
    private function skipForAnchorGap(
        ReleaseProcessingContext $context,
        CompressedTopUpFetch $fetch,
        int $segmentsWanted,
    ): bool {
        if ($fetch->currentVolume !== 1) {
            return false;
        }

        $expansionFetched = $fetch->anchorSegmentsHeld - count($fetch->anchor->messageIds);
        $anchorSegmentsNeeded = min($segmentsWanted, count($fetch->anchor->expansionMessageIds) - $expansionFetched);
        if ($fetch->anchorSegmentsHeld + $anchorSegmentsNeeded <= $fetch->anchor->contiguousHeadSegments) {
            return false;
        }

        $context->recordSegmentGapSkip();
        $this->output->echoSegmentGapSkip();

        return true;
    }

    private function segmentsForRemainingDuration(
        float $decodableSeconds,
        float $bytesPerSecond,
        int $perSegmentBytes,
    ): int {
        $remainingSeconds = max($this->config->previewTargetSeconds - $decodableSeconds, 0.0);

        return max((int) ceil(($remainingSeconds * $bytesPerSecond) / $perSegmentBytes), 1);
    }

    /**
     * @param  list<string>  $messageIds
     * @param  array<string, true>  $fetchedMessageIds
     * @return list<string>
     */
    private function unfetchedMessageIds(array $messageIds, array $fetchedMessageIds): array
    {
        $unfetched = [];
        foreach ($messageIds as $messageId) {
            if (! isset($fetchedMessageIds[$messageId])) {
                $fetchedMessageIds[$messageId] = true;
                $unfetched[] = $messageId;
            }
        }

        return $unfetched;
    }

    private function logCompressedTargetMet(ReleaseProcessingContext $context, float $decodableSeconds): void
    {
        Log::debug('Compressed preview top-up target-met', [
            'release_id' => $context->release->id,
            'decodable_seconds' => $decodableSeconds,
            'target_seconds' => $this->config->previewTargetSeconds,
        ]);
    }

    private function logCompressedProbeFailed(
        ReleaseProcessingContext $context,
        string $fragmentPath,
        string $reason,
    ): void {
        Log::debug('Compressed preview top-up probe-failed', [
            'release_id' => $context->release->id,
            'fragment' => $fragmentPath,
            'reason' => $reason,
        ]);
    }

    private function logCompressedExtractionFailed(
        ReleaseProcessingContext $context,
        string $reason,
        ?int $fragmentBytes = null,
    ): void {
        $details = [
            'release_id' => $context->release->id,
            'reason' => $reason,
        ];
        if ($fragmentBytes !== null) {
            $details['fragment_bytes'] = $fragmentBytes;
        }

        Log::debug('Compressed preview top-up extraction-failed', $details);
    }

    /**
     * Re-extract the leading video entry from the working volume set into the
     * extraction directory, replacing the previous fragment. Returns the
     * fragment's path, or null when extraction produced nothing.
     */
    private function extractPreviewFragment(
        string $firstVolumePath,
        string $videoEntryName,
        ReleaseProcessingContext $context,
    ): ?string {
        $fragmentPath = $this->archiveService->extractSpecificFileToPath(
            $firstVolumePath,
            $videoEntryName,
            $context->tmpPath.'unrar/',
            keepBroken: true,
            password: $context->nzbPassword,
        );

        return $fragmentPath !== null && File::isFile($fragmentPath) ? $fragmentPath : null;
    }

    /**
     * The first video file the fetched archive head lists, or null when the
     * data is not a listable archive or holds no video.
     *
     * @return array{name: string, size: int, compressed: int, pass: int, range?: mixed, next_offset?: mixed}|null
     */
    private function leadingArchiveVideoEntry(string $compressedData): ?array
    {
        foreach ($this->archiveService->listArchiveContents($compressedData)['files'] as $file) {
            $name = (string) ($file['name'] ?? '');
            if ($name !== '' && PostedFileClassifier::matchesTerminalExtension($name, $this->config->videoFileRegex)) {
                return [
                    'name' => $name,
                    'size' => max((int) ($file['size'] ?? 0), 0),
                    'compressed' => is_int($file['compressed'] ?? null) ? $file['compressed'] : 1,
                    'pass' => is_int($file['pass'] ?? null) ? $file['pass'] : 1,
                ] + array_intersect_key($file, ['range' => true, 'next_offset' => true]);
            }
        }

        return null;
    }

    /**
     * unrar finds a set's later volumes by the first volume's name, and which
     * pattern it tries follows the set's numbering flag: an old-numbered set
     * looks for .r00 next to its .rar, whatever else the file is called.
     */
    private function previewArchiveVolumePath(string $tmpPath, int $volume, bool $oldVolumeNumbers): string
    {
        if (! $oldVolumeNumbers) {
            return $tmpPath.'preview-archive.part'.sprintf('%03d', $volume).'.rar';
        }

        return $tmpPath.'preview-archive.'.($volume === 1 ? 'rar' : sprintf('r%02d', $volume - 2));
    }

    /**
     * Whether a first volume's main header marks an old-numbered RAR4 set.
     */
    private function usesOldVolumeNumbers(string $firstVolume): bool
    {
        if (! str_starts_with($firstVolume, "Rar!\x1A\x07\x00")
            || strlen($firstVolume) < 12
            || ord($firstVolume[9]) !== RarInfo::BLOCK_MAIN
        ) {
            return false;
        }

        return (unpack('v', $firstVolume, 10)[1] & RarInfo::MAIN_NEWNUMBERING) === 0;
    }

    /**
     * @param  array<string, array<string, mixed>>  $held  Sniff results by message-id, used instead of fetching again.
     * @param  list<ArchiveCandidate|SevenZip\PlaceholderVolume>|null  $volumes  The set's volumes when sniffing resolved them.
     */
    private function processSevenZip(
        ArchiveCandidate $anchor,
        ReleaseProcessingContext $context,
        bool $sniffed = false,
        array $held = [],
        ?array $volumes = null,
    ): void {
        if (isset($context->inspectedSevenZipTitles[$anchor->title])) {
            return;
        }
        $volumes ??= $sniffed ? [$anchor] : ($context->workPlan?->sevenZipVolumes($anchor->title) ?? []);
        $context->inspectedSevenZipTitles[$anchor->title] = true;
        foreach ($volumes as $volume) {
            if ($volume instanceof ArchiveCandidate) {
                $context->inspectedSevenZipTitles[$volume->title] = true;
            }
        }
        $budget = $context->passwordInspectionBudget ??= new SevenZip\InspectionBudget(
            max(1, (int) config('archive-inspection.seven_zip.max_articles', 32)),
            max(1, (int) config('archive-inspection.seven_zip.max_bytes', 8388608)),
            max(1, min((int) config('archive-inspection.seven_zip.max_seconds', 20),
                $this->config->releaseProcessingTimeout > 0 ? $this->config->releaseProcessingTimeout - $context->getElapsedSeconds() : 20)),
        );
        $ranges = new SevenZip\ArchiveRanges(
            $volumes,
            function (string $id) use ($context, $budget, $held): array {
                if (isset($held[$id])) {
                    return $held[$id];
                }
                $result = $this->downloadService->downloadInspectionArticle($id, $context->releaseGroupName, $budget);
                $context->groupUnavailable = $context->groupUnavailable || $result['groupUnavailable'];

                return $result;
            },
            $budget,
        );
        $workingSet = $context->tmpPath === '' ? null : new SevenZip\WorkingSet($ranges, $context->tmpPath.'sevenzip/');
        try {
            // The service performs validation before it can return a positive verdict.
            $this->processCompressedData(SevenZip\Inspector::SIGNATURE, $context, false, $anchor->title, $ranges->read(...), $workingSet);
            if ($workingSet !== null) {
                $this->processSevenZipPreview($workingSet, $context);
            }
        } finally {
            // The nested-archive sweep would read a working set left behind as a lone 7z.
            $workingSet?->delete();
        }
    }

    /**
     * A preview from inside a verified 7z: 7-Zip lists the working set, and the
     * first video entry's head is fetched where its block starts, written into
     * the set, and extracted. Any step that cannot complete ends it quietly.
     */
    private function processSevenZipPreview(SevenZip\WorkingSet $workingSet, ReleaseProcessingContext $context): void
    {
        if (! $this->archiveService->canRunSevenZip($context)
            || ! in_array($workingSet->inspectionVerdict(), [Enums\PasswordVerdict::VerifiedUnencrypted, Enums\PasswordVerdict::VerifiedEncrypted], true)
            || $context->releaseDiscarded
            || $context->releaseHasPassword
            || ($context->foundMediaInfo && $context->foundSample && $context->foundVideo)
            || $context->isTimedOut($this->config->releaseProcessingTimeout)) {
            return;
        }

        $listing = $this->archiveService->sevenZipListing($workingSet, $context);
        if ($listing->exitCode !== 0) {
            return;
        }
        $video = null;
        $blockStart = 32;
        $solidBytes = 0;
        $before = [];
        foreach ($listing->entries as $entry) {
            if (PostedFileClassifier::matchesTerminalExtension($entry['path'], $this->config->videoFileRegex)) {
                $video = $entry;

                break;
            }
            $before[] = $entry;
        }
        if ($video === null) {
            return;
        }
        // Blocks are stored one after another from byte 32, in listing order;
        // a solid block decodes the entries before the video first.
        foreach ($before as $entry) {
            if ($entry['block'] === $video['block']) {
                $solidBytes += $entry['size'];
            } else {
                $blockStart += $entry['packedSize'];
            }
        }

        $volumeIndex = null;
        foreach ($workingSet->ranges->volumes() as $index => $volume) {
            if ($volume['start'] <= $blockStart && $blockStart < $volume['start'] + $volume['size']) {
                $volumeIndex = $index;

                break;
            }
        }
        $volume = $volumeIndex === null ? null : $workingSet->ranges->volumes()[$volumeIndex];
        if ($volume === null || $volume['placeholder']) {
            return;
        }
        $offset = $blockStart - $volume['start'];
        $head = $workingSet->ranges->heldArticle($volume['ids'][0]);
        if ($head === null) {
            return;
        }
        $segmentIndex = intdiv($offset, $head['metadata']->length);
        if (! isset($volume['ids'][$segmentIndex])) {
            return;
        }

        if ($segmentIndex === 0) {
            $segment = $head;
        } else {
            $download = $this->downloadService->download(
                DownloadKind::Compressed,
                [$volume['ids'][$segmentIndex]],
                $context->releaseGroupName,
                $context->release->id,
            );
            if ($download['groupUnavailable']) {
                $context->groupUnavailable = true;

                return;
            }
            $metadata = $download['metadata'] ?? null;
            if (! $download['success'] || ! is_string($download['data']) || ! $metadata instanceof YencArticleMetadata
                || $offset < $metadata->offset || $offset >= $metadata->offset + strlen($download['data'])) {
                return;
            }
            $segment = ['data' => $download['data'], 'metadata' => $metadata];
        }

        $window = $segment['data'];
        $following = array_slice($volume['ids'], $segmentIndex + 1, max(0, $this->config->segmentsToDownload - 1));
        if ($following !== []) {
            $download = $this->downloadService->download(
                DownloadKind::Compressed,
                $following,
                $context->releaseGroupName,
                $context->release->id,
            );
            if ($download['groupUnavailable']) {
                $context->groupUnavailable = true;

                return;
            }
            if ($download['success'] && is_string($download['data'])) {
                $window .= $download['data'];
            }
        }
        try {
            $workingSet->write($volumeIndex, $segment['metadata']->offset, $window);
        } catch (\RuntimeException) {
            return;
        }

        $bytes = min($segment['metadata']->offset + strlen($window) - $offset - $solidBytes, $video['size']);
        if ($bytes < 1) {
            return;
        }
        $extension = preg_replace('/[^A-Za-z0-9]/', '', pathinfo($video['path'], PATHINFO_EXTENSION)) ?: 'bin';
        $fragment = $context->tmpPath.'sevenzip_preview_'.uniqid('', true).'.'.$extension;
        try {
            $this->archiveService->extractSevenZipHead($workingSet, $context, $video['path'], $bytes, $fragment);
            if (! File::isFile($fragment) || File::size($fragment) < 1) {
                return;
            }
            $this->mediaService->processVideoFile(
                $fragment,
                $context,
                $context->tmpPath,
                $this->mediaInfoContext(basename($video['path']), MediaInfoSourceCompleteness::Partial),
            );
        } finally {
            File::delete($fragment);
        }
    }

    private function processCompressedData(
        string $compressedData,
        ReleaseProcessingContext $context,
        bool $reverse,
        string $archiveTitle = '',
        ?\Closure $archiveRead = null,
        ?SevenZip\WorkingSet $workingSet = null,
    ): bool {
        $result = match (true) {
            $archiveRead === null => $this->archiveService->processCompressedData($compressedData, $context, $context->tmpPath),
            $workingSet === null => $this->archiveService->processCompressedData($compressedData, $context, $context->tmpPath, $archiveRead),
            default => $this->archiveService->processCompressedData($compressedData, $context, $context->tmpPath, $archiveRead, $workingSet),
        };

        if (isset($result['verdict'])) {
            $context->recordPasswordVerdict($result['verdict'], $result['inspectionReason']);
            if ($result['files'] !== [] && ($result['manifestComplete'] ?? false)) {
                $this->releaseManager->processReleaseNameFromRar($result['dataSummary'], $context);
            }
            foreach ($result['files'] as $file) {
                $this->releaseManager->addFileInfo($file, $context, $this->config->supportFileRegex);
                if ($context->releaseDiscarded) {
                    return false;
                }
            }
        }

        if ($result['hasPassword']) {
            $context->releaseHasPassword = true;
            $context->passwordStatus = $result['passwordStatus'];

            return true;
        }

        if (isset($result['verdict'])) {
            return $result['success'];
        }

        if (isset($result['standaloneVideoType'])) {
            $this->output->echoInlineVideo();
            $fileLocation = $context->tmpPath.'inline_video_'.uniqid('', true).'.'.$result['standaloneVideoType'];
            File::put($fileLocation, $result['standaloneVideoData']);

            if (! $context->foundMediaInfo && $this->mediaService->getMediaInfo(
                $fileLocation,
                $context->release->id,
                $this->mediaInfoContext(
                    PostedFileClassifier::postedFilename($archiveTitle),
                    MediaInfoSourceCompleteness::Unknown,
                ),
            )) {
                $context->markFound('mediaInfo');
                $this->output->echoMediaInfoAdded();
            }
            if (! $context->foundSample && $this->mediaService->getSample($fileLocation, $context->tmpPath, $context->release->guid)) {
                $context->markFound('sample');
                $this->output->echoSampleCreated();
            }
            if (! $context->foundVideo && $this->mediaService->getVideo($fileLocation, $context->tmpPath, $context->release->guid, (int) $context->release->categories_id)) {
                $context->markFound('video');
                $this->output->echoVideoCreated();
            }

            return $context->foundMediaInfo || $context->foundSample || $context->foundVideo;
        }

        if (! $result['success']) {
            return false;
        }

        if (! empty($result['archiveMarker'])) {
            $this->output->echoArchiveMarker($result['archiveMarker']);
        }

        if (! empty($result['dataSummary']) && ($result['manifestComplete'] ?? false)) {
            $this->releaseManager->processReleaseNameFromRar($result['dataSummary'], $context);
        }

        foreach ($result['files'] as $file) {
            if ($this->releaseManager->addFileInfo($file, $context, $this->config->supportFileRegex)) {
                $this->output->echoFileInfoAdded();
            }

            if ($context->releaseDiscarded) {
                return false;
            }
        }

        if ($context->releaseHasNoNFO
            && is_array($result['files'])
            && $this->containsNfoCandidate($result['files'])
        ) {
            $context->rememberDownloadedArchive($archiveTitle, $compressedData, $result['files']);
        }

        if (! $context->foundJPGSample && $this->config->processJPGSample) {
            $this->archiveFallback->processJpgFromArchiveFileList($compressedData, $result['files'], $context);
        }

        return $context->totalFileInfo > 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     */
    private function containsNfoCandidate(array $files): bool
    {
        foreach ($files as $file) {
            if ($this->archiveService->isNfoFile((string) ($file['name'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    private function processExtractedFiles(ReleaseProcessingContext $context): void
    {
        $nestedLevels = 0;

        while ($nestedLevels < $this->config->maxNestedLevels) {
            if ($context->releaseDiscarded
                || $context->compressedFilesChecked >= AdditionalProcessingOrchestrator::MAX_COMPRESSED_FILES_TO_CHECK
            ) {
                break;
            }

            $foundCompressed = false;

            try {
                $files = $this->tempWorkspace->listFiles($context->tmpPath, self::NESTED_ARCHIVE_PATTERN);
            } catch (\Throwable) {
                break;
            }

            foreach ($files as $file) {
                $filePath = is_array($file) ? $file[0] : $file->getPathname();
                if (! File::isFile($filePath)) {
                    continue;
                }

                $rarData = @File::get($filePath);
                if (! empty($rarData)) {
                    $this->processCompressedData($rarData, $context, false, basename($filePath));
                    $foundCompressed = true;
                }
                File::delete($filePath);
            }

            if (! $foundCompressed) {
                break;
            }
            $nestedLevels++;
        }

        try {
            $files = $this->tempWorkspace->listFiles($context->tmpPath);
        } catch (\Throwable) {
            return;
        }

        foreach ($files as $file) {
            if ($context->releaseDiscarded) {
                return;
            }

            $filePath = is_object($file) ? $file->getPathname() : $file;

            if (preg_match('/[\/\\\\]\.{1,2}$/', $filePath) === 1 || ! File::isFile($filePath)) {
                continue;
            }

            if (! $context->foundPAR2Info && preg_match('/\.par2$/i', $filePath) === 1) {
                $this->releaseManager->processPar2File(
                    $filePath,
                    $context,
                    $this->archiveService->getPar2Info()
                );

                continue;
            }

            if ($context->releaseHasNoNFO) {
                if (preg_match('/(\.(nfo|inf|ofn|diz)|info\.txt)$/i', $filePath) === 1) {
                    if ($this->releaseManager->processNfoFile($filePath, $context, $this->downloadService->getNNTP())) {
                        $this->output->echoNfoFound();
                    }

                    continue;
                }

                if ($this->releaseManager->isNfoFilename($filePath)) {
                    if ($this->releaseManager->processNfoFile($filePath, $context, $this->downloadService->getNNTP())) {
                        $this->output->echoNfoFound();
                    }

                    continue;
                }
            }

            if (! $context->foundJPGSample && preg_match('/\.(jpe?g|png|webp)$/i', $filePath) === 1) {
                if ($this->mediaService->getJPGSample($filePath, $context->release->guid)) {
                    $context->markFound('jpgSample');
                    $this->output->echoJpgSaved();
                }
                File::delete($filePath);

                continue;
            }

            if ((! $context->foundSample || ! $context->foundVideo || ! $context->foundMediaInfo)
                && preg_match('/(.*)'.$this->config->videoFileRegex.'$/i', $filePath) === 1
            ) {
                $this->mediaService->processVideoFile(
                    $filePath,
                    $context,
                    $context->tmpPath,
                    $this->mediaInfoContext(basename($filePath), MediaInfoSourceCompleteness::Complete),
                );

                continue;
            }

            $output = fileInfo($filePath);
            if ($output === '' || $output === null) {
                continue;
            }

            if (! $context->foundJPGSample && preg_match('/^JPE?G|^PNG|^WebP/i', $output) === 1) {
                if ($this->mediaService->getJPGSample($filePath, $context->release->guid)) {
                    $context->markFound('jpgSample');
                    $this->output->echoJpgSaved();
                }
                File::delete($filePath);
            } elseif ((! $context->foundMediaInfo || ! $context->foundSample || ! $context->foundVideo)
                && preg_match('/Matroska data|MPEG v4|MPEG sequence, v2|\WAVI\W/i', $output) === 1
            ) {
                $this->mediaService->processVideoFile(
                    $filePath,
                    $context,
                    $context->tmpPath,
                    $this->mediaInfoContext(basename($filePath), MediaInfoSourceCompleteness::Complete),
                );
            } elseif (! $context->foundPAR2Info && stripos($output, 'Parity') === 0) {
                $this->releaseManager->processPar2File(
                    $filePath,
                    $context,
                    $this->archiveService->getPar2Info()
                );
            }
        }
    }

    private function mediaInfoContext(
        ?string $sourceFilename,
        MediaInfoSourceCompleteness $completeness,
    ): MediaInfoProbeContext {
        return new MediaInfoProbeContext(
            MediaInfoSourceKind::AdditionalProcessing,
            $sourceFilename,
            $completeness,
        );
    }
}
