<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Models\UsenetGroup;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\DTO\ReleaseProcessingResult;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\Enums\Mp4MoovSpliceStatus;
use App\Services\AdditionalProcessing\Enums\PayloadClassification;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\Enums\ProcessingStage;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ProcessingMetrics;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\TempWorkspaceService;
use Illuminate\Support\Facades\File;

/**
 * Stateless release processor for additional post-processing.
 */
class ReleaseProcessor
{
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
            $releaseNameChanged = false;
            $releaseNeededNfo = false;
            $nzbResult = $metrics->measure(
                ProcessingStage::NzbParsing,
                fn (): array => $this->nzbParser->parseNzb($release->guid),
            );
            if ($nzbResult['error'] !== null) {
                $this->output->warning($nzbResult['error']);
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

        $context->initializeFromConfig(
            $this->config->processVideo && $previewGenerationAllowed,
            $this->config->processMediaInfo,
            $this->config->processJPGSample,
            $this->config->processThumbnails && $previewGenerationAllowed
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

    private function processingTimeoutResult(
        ReleaseProcessingContext $context,
        ProcessingMetrics $metrics,
    ): ?ReleaseProcessingResult {
        if (! $context->isTimedOut($this->config->releaseProcessingTimeout)) {
            return null;
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
        // not count as created artifacts.
        $previewGenerationAllowed = ! $context->previewGenerationSkippedByPolicy;

        return $context->releaseFilesChanged
            || $context->foundPAR2Info
            || ($releaseNeededNfo && ! $context->releaseHasNoNFO)
            || ($this->config->processVideo && $previewGenerationAllowed && $context->foundVideo)
            || ($this->config->processThumbnails && $previewGenerationAllowed && $context->foundSample)
            || ($this->config->processJPGSample && $context->foundJPGSample)
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
        /** @var list<array{payload: string, candidate: ArchiveCandidate}> $deferredArchives */
        $deferredArchives = [];

        foreach ($context->workPlan->unknownPayloadCandidates as $candidate) {
            if ($context->isTimedOut($this->config->releaseProcessingTimeout)) {
                return;
            }

            $result = $this->downloadService->download(
                DownloadKind::PayloadSniff,
                [$candidate->firstMessageId],
                $context->releaseGroupName,
                $context->release->id,
                $candidate->title,
            );

            if ($result['groupUnavailable']) {
                $context->groupUnavailable = true;
                $this->output->echoGroupUnavailable();

                return;
            }

            if (! $result['success'] || ! is_string($result['data'])) {
                continue;
            }

            $payload = $result['data'];
            $sniffResult = $this->payloadSniffer->classify($payload);
            $classification = $sniffResult->classification;
            $context->recordPayloadClassification($classification);

            if (in_array($classification, [PayloadClassification::Rar, PayloadClassification::Zip], true)) {
                $archiveCandidate = new ArchiveCandidate(
                    title: $candidate->title,
                    messageIds: [$candidate->firstMessageId],
                    likelyFirstVolume: $sniffResult->likelyFirstVolume,
                    sourceIndex: $candidate->sourceIndex,
                );
                if ($archiveCandidate->likelyFirstVolume) {
                    $this->processCompressedData($payload, $context, false, $archiveCandidate->title);
                } else {
                    $deferredArchives[] = ['payload' => $payload, 'candidate' => $archiveCandidate];
                }

                continue;
            }

            match ($classification) {
                PayloadClassification::Par2 => $this->processSniffedPar2($payload, $context),
                PayloadClassification::Matroska, PayloadClassification::Mp4, PayloadClassification::Avi => $this->processSniffedVideo(
                    $payload,
                    $classification,
                    $context,
                ),
                PayloadClassification::Text => $this->processSniffedNfo($payload, $context),
                PayloadClassification::Unknown => null,
            };
        }

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

    private function processSniffedPar2(string $payload, ReleaseProcessingContext $context): void
    {
        if ($context->foundPAR2Info) {
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

    private function processSniffedVideo(
        string $payload,
        PayloadClassification $classification,
        ReleaseProcessingContext $context,
    ): void {
        $extension = $classification->mediaExtension() ?? 'bin';
        $path = $context->tmpPath.'sniffed_'.uniqid('', true).'.'.$extension;
        File::put($path, $payload);
        try {
            $this->mediaService->processVideoFile($path, $context, $context->tmpPath);
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
                if (! $context->foundVideo && $this->mediaService->getVideo($fileLocation, $context->tmpPath, $context->release->guid)) {
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
                $this->output->echoMediaInfoDownload();
                $fileLocation = $context->tmpPath.'media.avi';
                File::put($fileLocation, $result['data']);
                $splicedData = $this->spliceMp4Tail($result['data'], $context);
                if ($splicedData !== null) {
                    $fileLocation = $context->tmpPath.'media.mp4';
                    File::put($fileLocation, $splicedData);
                }

                if (! $context->foundMediaInfo && $this->mediaService->getMediaInfo($fileLocation, $context->release->id)) {
                    $context->markFound('mediaInfo');
                    $this->output->echoMediaInfoAdded();
                }
                if (! $context->foundSample && $this->mediaService->getSample($fileLocation, $context->tmpPath, $context->release->guid)) {
                    $context->markFound('sample');
                    $this->output->echoSampleCreated();
                }
                if (! $context->foundVideo && $this->mediaService->getVideo($fileLocation, $context->tmpPath, $context->release->guid)) {
                    $context->markFound('video');
                    $this->output->echoVideoCreated();
                }
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

    private function spliceMp4Tail(string $head, ReleaseProcessingContext $context): ?string
    {
        if (! $this->config->mp4TailFetch
            || $this->payloadSniffer->classify($head)->classification !== PayloadClassification::Mp4
            || ! $this->mp4MoovSplicer->needsTail($head)
        ) {
            return null;
        }

        $plan = $context->workPlan;
        if ($plan === null || $plan->mediaInfoTailMessageIds === []) {
            $context->recordMp4MoovMissing();
            $this->output->echoMp4MoovMissing();

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

            if ($splice->status === Mp4MoovSpliceStatus::Spliced) {
                $context->recordMp4MoovFound();
                $this->output->echoMp4MoovFound();

                return $splice->data;
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
     * @param  list<string>  $triedCompressedMids
     */
    private function processNzbCompressedFiles(
        ReleaseProcessingContext $context,
        bool $reverse,
        array &$triedCompressedMids
    ): void {
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
                if ($processed) {
                    break;
                }
            } else {
                $failed++;
                $this->output->echoCompressedFailure($failed);
            }
        }
    }

    private function processCompressedData(
        string $compressedData,
        ReleaseProcessingContext $context,
        bool $reverse,
        string $archiveTitle = '',
    ): bool {
        $result = $this->archiveService->processCompressedData(
            $compressedData,
            $context,
            $context->tmpPath
        );

        if ($result['hasPassword']) {
            $context->releaseHasPassword = true;
            $context->passwordStatus = $result['passwordStatus'];

            return true;
        }

        if (isset($result['standaloneVideoType'])) {
            $this->output->echoInlineVideo();
            $fileLocation = $context->tmpPath.'inline_video_'.uniqid('', true).'.'.$result['standaloneVideoType'];
            File::put($fileLocation, $result['standaloneVideoData']);

            if (! $context->foundMediaInfo && $this->mediaService->getMediaInfo($fileLocation, $context->release->id)) {
                $context->markFound('mediaInfo');
                $this->output->echoMediaInfoAdded();
            }
            if (! $context->foundSample && $this->mediaService->getSample($fileLocation, $context->tmpPath, $context->release->guid)) {
                $context->markFound('sample');
                $this->output->echoSampleCreated();
            }
            if (! $context->foundVideo && $this->mediaService->getVideo($fileLocation, $context->tmpPath, $context->release->guid)) {
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

        if ($reverse && ! empty($result['dataSummary'])) {
            $this->releaseManager->processReleaseNameFromRar($result['dataSummary'], $context);
        }

        foreach ($result['files'] as $file) {
            if ($context->releaseHasPassword) {
                break;
            }

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
            $pattern = '/.*\.([rz]\d{2,}|rar|zipx?|0{0,2}1)($|[^a-z0-9])/i';

            try {
                $files = $this->tempWorkspace->listFiles($context->tmpPath, $pattern);
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
                $this->mediaService->processVideoFile($filePath, $context, $context->tmpPath);

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
                $this->mediaService->processVideoFile($filePath, $context, $context->tmpPath);
            } elseif (! $context->foundPAR2Info && stripos($output, 'Parity') === 0) {
                $this->releaseManager->processPar2File(
                    $filePath,
                    $context,
                    $this->archiveService->getPar2Info()
                );
            }
        }
    }
}
