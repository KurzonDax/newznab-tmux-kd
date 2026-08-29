<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Models\Release;
use App\Models\ReleaseAudioTag;
use App\Services\AdditionalProcessing\AudioTagExtractor;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AudioProcessing\DTO\AudioProcessingResult;
use App\Services\AudioProcessing\Enums\AudioSourceKind;
use App\Services\AudioProcessing\Exceptions\WavPackDecoderUnavailable;
use App\Services\Categorization\MediaInfoRefinementService;
use App\Services\ReleaseExtraService;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\Releases\ReleaseBrowseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mhor\MediaInfo\Container\MediaInfoContainer;

/**
 * Turns one pending music release into a preview, a spectrogram, and a tag row.
 *
 * The order matters and is the reason this path exists separately: tags are
 * written the moment the first article has been read, before anything else is
 * fetched, so a release that turns out to be video -- or whose fetch fails
 * halfway -- still leaves behind everything MediaInfo could tell us about it.
 */
final class AudioReleaseProcessor
{
    private int $crcFailures = 0;

    public function __construct(
        private readonly NzbContentParser $nzbParser,
        private readonly AudioSourceSelector $sourceSelector,
        private readonly AudioFetcher $fetcher,
        private readonly AudioPreviewEncoder $encoder,
        private readonly AudioTagExtractor $tagExtractor,
        private readonly AudioTagRenamer $renamer,
        private readonly ReleaseExtraService $releaseExtra,
        private readonly MediaInfoRefinementService $mediaInfoRefinement,
        private readonly ReleaseSearchSyncCoordinator $searchSyncCoordinator,
        private readonly PreviewGenerationPolicy $previewPolicy,
    ) {}

    public function process(Release $release, string $tmpPath, string $groupName): AudioProcessingResult
    {
        $this->crcFailures = 0;
        $releaseId = (int) $release->id;
        $guid = (string) $release->guid;
        $tagsRecorded = false;

        $parsed = $this->nzbParser->parseNzb($guid);
        if ($parsed['error'] !== null) {
            return $this->finish($release, false, $tagsRecorded, ProcessingOutcome::Failed, (string) $parsed['error']);
        }

        /** @var list<array<string, mixed>> $contents */
        $contents = array_values($parsed['contents']);
        $source = $this->sourceSelector->select($contents);
        if ($source === null) {
            return $this->finish($release, false, $tagsRecorded, ProcessingOutcome::NoUsefulArtifacts, 'The NZB holds no audio file or archive.');
        }

        if ($source->kind === AudioSourceKind::Archive && $this->archiveAttemptReachedCap($release)) {
            return $this->finish(
                $release,
                false,
                $tagsRecorded,
                ProcessingOutcome::NoUsefulArtifacts,
                'The audio archive crash guard reached its attempt cap.',
            );
        }

        $fetched = $this->fetcher->fetch(
            $release,
            $source,
            $tmpPath,
            $groupName,
            function (MediaInfoContainer $container, string $sourceFilename, string $extension) use ($release, &$tagsRecorded): void {
                $tagsRecorded = $this->recordTags($release, $container, $sourceFilename, $extension);
            },
        );
        $this->crcFailures = $fetched->crcFailures;

        if ($fetched->declined) {
            if (! AudioCandidateQuery::declineToVideoPath($releaseId)) {
                // Nowhere to record the hand-off, so settle the release instead:
                // leaving it pending would have it re-probed every cycle forever.
                return $this->finish($release, false, $tagsRecorded, ProcessingOutcome::NoUsefulArtifacts, $fetched->reason);
            }

            $this->searchSyncCoordinator->request($releaseId);

            return new AudioProcessingResult(
                releaseId: $releaseId,
                guid: $guid,
                outcome: ProcessingOutcome::DeclinedToVideoPath,
                tagsRecorded: $tagsRecorded,
                reason: $fetched->reason,
                crcFailures: $this->crcFailures,
            );
        }

        if (! $fetched->succeeded() || $fetched->path === null) {
            return $this->finish(
                $release,
                false,
                $tagsRecorded,
                ProcessingOutcome::NoUsefulArtifacts,
                $fetched->reason,
                $fetched->archivePassworded
                    ? ReleaseBrowseService::PASSWD_RAR
                    : ReleaseBrowseService::PASSWD_NONE,
            );
        }

        try {
            // ADR 0004: an operator who turned preview generation off for the
            // Music root gets no ffmpeg artifacts here either. Tags are already
            // written, and the sentinel below records that generation is owed if
            // the toggle is ever turned back on.
            if (! $this->previewPolicy->generationEnabledForCategory((int) $release->categories_id)) {
                return $this->finishSkippedByPolicy($release, $tagsRecorded);
            }

            try {
                $preview = $this->encoder->encode($fetched->path, $guid, $tmpPath);
            } catch (WavPackDecoderUnavailable $exception) {
                return $this->finish(
                    $release,
                    false,
                    $tagsRecorded,
                    ProcessingOutcome::NoUsefulArtifacts,
                    $exception->getMessage(),
                );
            }
            if ($preview === null) {
                return $this->finish($release, false, $tagsRecorded, ProcessingOutcome::NoUsefulArtifacts, 'ffmpeg produced no preview clip.');
            }

            $preview = $preview->withSpectrogram(
                $this->encoder->renderSpectrogram($fetched->path, $guid, $tmpPath)
            );

            $this->recordPreview($releaseId, $preview);

            return $this->finish($release, true, $tagsRecorded, ProcessingOutcome::Completed);
        } finally {
            File::delete($fetched->path);
        }
    }

    private function archiveAttemptReachedCap(Release $release): bool
    {
        $maximum = ReleaseClaimant::maxPpTimeoutCount();

        $reachedCap = DB::transaction(function () use ($release, $maximum): bool {
            $query = Release::query()->whereKey($release->id);
            if (DB::getDriverName() !== 'sqlite') {
                $query->lockForUpdate();
            }

            $currentCount = (int) $query->value('pp_timeout_count');
            $newCount = $currentCount + 1;

            if ($newCount >= $maximum) {
                Release::query()->whereKey($release->id)->update(array_merge([
                    'haspreview' => 0,
                    'passwordstatus' => ReleaseBrowseService::PASSWD_NONE,
                ], ReleaseClaimant::settlementValues()));
                $release->pp_timeout_count = 0;

                return true;
            }

            Release::query()->whereKey($release->id)->update(['pp_timeout_count' => $newCount]);
            $release->pp_timeout_count = $newCount;

            return false;
        }, 3);

        if ($reachedCap) {
            Log::warning(
                'Release '.$release->id.' reached the audio archive crash guard '
                .'('.$maximum.'/'.$maximum.') and was settled before archive fetching.'
            );
        }

        return $reachedCap;
    }

    /**
     * Persist what MediaInfo read off the head of the audio file.
     *
     * Runs before anything past the first article is fetched, so the row exists
     * even for a release the rest of this method gives up on.
     */
    private function recordTags(
        Release $release,
        MediaInfoContainer $container,
        string $sourceFilename,
        string $extension,
    ): bool {
        $releaseId = (int) $release->id;

        try {
            $tags = $this->tagExtractor->extract($container, $sourceFilename);
            if ($tags === null) {
                return false;
            }

            // Written before the rename, so the row survives even where renaming
            // is switched off or the release already has a predb name.
            ReleaseAudioTag::query()->updateOrCreate(['releases_id' => $releaseId], $tags);
            $this->renamer->rename($release, $tags, $extension);
            $this->releaseExtra->addFromXml($releaseId, $container);
            $this->mediaInfoRefinement->refine($releaseId);

            return true;
        } catch (\Throwable $e) {
            Log::debug('Audio tag persistence failed for release '.$releaseId.': '.$e->getMessage());

            return false;
        }
    }

    private function recordPreview(int $releaseId, DTO\AudioPreviewResult $preview): void
    {
        ReleaseAudioTag::query()->updateOrCreate(['releases_id' => $releaseId], [
            'has_preview' => 1,
            'preview_extension' => $preview->extension,
            'preview_mime' => $preview->mimeType,
            'preview_seconds' => $preview->seconds,
            'preview_bytes' => $preview->bytes,
            'has_spectrogram' => $preview->spectrogram ? 1 : 0,
        ]);
    }

    /**
     * Take the release off the pending set and let search see the result.
     */
    private function finish(
        Release $release,
        bool $previewCreated,
        bool $tagsRecorded,
        ProcessingOutcome $outcome,
        string $reason = '',
        int $passwordStatus = ReleaseBrowseService::PASSWD_NONE,
    ): AudioProcessingResult {
        return $this->settle(
            $release,
            $previewCreated ? 1 : 0,
            $previewCreated,
            $tagsRecorded,
            $outcome,
            $reason,
            $passwordStatus,
        );
    }

    private function finishSkippedByPolicy(Release $release, bool $tagsRecorded): AudioProcessingResult
    {
        return $this->settle(
            $release,
            PreviewGenerationPolicy::HASPREVIEW_SKIPPED_BY_POLICY,
            false,
            $tagsRecorded,
            ProcessingOutcome::NoUsefulArtifacts,
            'Preview generation is disabled for this release\'s root category.',
            ReleaseBrowseService::PASSWD_NONE,
        );
    }

    private function settle(
        Release $release,
        int $hasPreview,
        bool $previewCreated,
        bool $tagsRecorded,
        ProcessingOutcome $outcome,
        string $reason,
        int $passwordStatus,
    ): AudioProcessingResult {
        $releaseId = (int) $release->id;

        Release::query()->where('id', $releaseId)->update(array_merge([
            'haspreview' => $hasPreview,
            'passwordstatus' => $passwordStatus,
        ], ReleaseClaimant::settlementValues()));

        $this->searchSyncCoordinator->request($releaseId);

        return new AudioProcessingResult(
            releaseId: $releaseId,
            guid: (string) $release->guid,
            outcome: $outcome,
            previewCreated: $previewCreated,
            tagsRecorded: $tagsRecorded,
            reason: $reason,
            crcFailures: $this->crcFailures,
        );
    }
}
