<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Models\Release;
use App\Models\ReleaseAudioTag;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\AudioTagExtractor;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AudioProcessing\DTO\AudioProcessingResult;
use App\Services\Categorization\MediaInfoRefinementService;
use App\Services\ReleaseExtraService;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\Releases\ReleaseBrowseService;
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
    public function __construct(
        private readonly NzbContentParser $nzbParser,
        private readonly AudioSourceSelector $sourceSelector,
        private readonly AudioFetcher $fetcher,
        private readonly AudioPreviewEncoder $encoder,
        private readonly AudioTagExtractor $tagExtractor,
        private readonly ReleaseExtraService $releaseExtra,
        private readonly MediaInfoRefinementService $mediaInfoRefinement,
        private readonly ReleaseSearchSyncCoordinator $searchSyncCoordinator,
        private readonly PreviewGenerationPolicy $previewPolicy,
    ) {}

    public function process(Release $release, string $tmpPath, string $groupName): AudioProcessingResult
    {
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

        $fetched = $this->fetcher->fetch(
            $release,
            $source,
            $tmpPath,
            $groupName,
            function (MediaInfoContainer $container, string $sourceFilename) use ($releaseId, &$tagsRecorded): void {
                $tagsRecorded = $this->recordTags($releaseId, $container, $sourceFilename);
            },
        );

        if ($fetched->declined) {
            AudioCandidateQuery::declineToVideoPath($releaseId);
            $this->searchSyncCoordinator->request($releaseId);

            return new AudioProcessingResult(
                releaseId: $releaseId,
                guid: $guid,
                outcome: ProcessingOutcome::DeclinedToVideoPath,
                tagsRecorded: $tagsRecorded,
                reason: $fetched->reason,
            );
        }

        if (! $fetched->succeeded() || $fetched->path === null) {
            return $this->finish($release, false, $tagsRecorded, ProcessingOutcome::NoUsefulArtifacts, $fetched->reason);
        }

        try {
            // ADR 0004: an operator who turned preview generation off for the
            // Music root gets no ffmpeg artifacts here either. Tags are already
            // written, and the sentinel below records that generation is owed if
            // the toggle is ever turned back on.
            if (! $this->previewPolicy->generationEnabledForCategory((int) $release->categories_id)) {
                return $this->finishSkippedByPolicy($release, $tagsRecorded);
            }

            $preview = $this->encoder->encode($fetched->path, $guid, $tmpPath);
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

    /**
     * Persist what MediaInfo read off the head of the audio file.
     *
     * Runs before anything past the first article is fetched, so the row exists
     * even for a release the rest of this method gives up on.
     */
    private function recordTags(int $releaseId, MediaInfoContainer $container, string $sourceFilename): bool
    {
        try {
            $tags = $this->tagExtractor->extract($container, $sourceFilename);
            if ($tags === null) {
                return false;
            }

            ReleaseAudioTag::query()->updateOrCreate(['releases_id' => $releaseId], $tags);
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
    ): AudioProcessingResult {
        return $this->settle(
            $release,
            $previewCreated ? 1 : 0,
            $previewCreated,
            $tagsRecorded,
            $outcome,
            $reason,
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
        );
    }

    private function settle(
        Release $release,
        int $hasPreview,
        bool $previewCreated,
        bool $tagsRecorded,
        ProcessingOutcome $outcome,
        string $reason,
    ): AudioProcessingResult {
        $releaseId = (int) $release->id;

        Release::query()->where('id', $releaseId)->update(array_merge([
            'haspreview' => $hasPreview,
            'passwordstatus' => ReleaseBrowseService::PASSWD_NONE,
        ], AdditionalCandidateQuery::claimResetValues()));

        $this->searchSyncCoordinator->request($releaseId);

        return new AudioProcessingResult(
            releaseId: $releaseId,
            guid: (string) $release->guid,
            outcome: $outcome,
            previewCreated: $previewCreated,
            tagsRecorded: $tagsRecorded,
            reason: $reason,
        );
    }
}
