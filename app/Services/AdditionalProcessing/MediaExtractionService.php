<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Enums\ImageAssetProfile;
use App\Models\Release;
use App\Models\ReleaseVideoClip;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AudioProcessing\AudioReleaseProcessor;
use App\Services\Categorization\MediaInfoRefinementService;
use App\Services\ReleaseExtraService;
use App\Services\ReleaseImageService;
use App\Services\Releases\ClipGenerationPolicy;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mhor\MediaInfo\MediaInfo;

/**
 * Service for processing media files (video and images).
 *
 * Handles sample generation, thumbnails and media info extraction. Audio has
 * its own path -- see {@see AudioReleaseProcessor}.
 */
class MediaExtractionService
{
    private ?MediaTools $mediaTools = null;

    private readonly ReleaseSearchSyncCoordinator $searchSyncCoordinator;

    private readonly MediaInfoRefinementService $mediaInfoRefinement;

    public function __construct(
        private readonly ProcessingConfiguration $config,
        private readonly ReleaseImageService $releaseImage,
        private readonly ReleaseExtraService $releaseExtra,
        private readonly VideoFrameExtractor $videoFrameExtractor,
        ?ReleaseSearchSyncCoordinator $searchSyncCoordinator = null,
        ?MediaInfoRefinementService $mediaInfoRefinement = null,
        private readonly ClipGenerationPolicy $clipPolicy = new ClipGenerationPolicy,
        private readonly VideoClipEncoder $clipEncoder = new VideoClipEncoder,
        private readonly FreeDiskGuard $freeDiskGuard = new FreeDiskGuard,
    ) {
        $this->searchSyncCoordinator = $searchSyncCoordinator
            ?? new ReleaseSearchSyncCoordinator(
                new PersistenceMetricsCollector,
            );
        $this->mediaInfoRefinement = $mediaInfoRefinement
            ?? new MediaInfoRefinementService(searchSyncCoordinator: $this->searchSyncCoordinator);
    }

    /**
     * Extract a sample image from a video file.
     */
    public function getSample(string $fileLocation, string $tmpPath, string $guid): bool
    {
        if (! $this->config->processThumbnails || ! File::isFile($fileLocation)) {
            return false;
        }

        $fileName = $tmpPath.'zzzz'.random_int(5, 12).random_int(5, 12).'.jpg';

        try {
            if (! $this->videoFrameExtractor->extractRepresentativeFrame($fileLocation, $fileName)) {
                return false;
            }

            return $this->releaseImage->saveExtractedImage(
                $guid,
                $fileName,
                $this->releaseImage->imgSavePath,
                ImageAssetProfile::Preview,
            )->success;
        } catch (\Throwable $e) {
            if ($this->config->debugMode) {
                Log::error($e->getTraceAsString());
            }

            return false;
        } finally {
            File::delete($fileName);
        }
    }

    /**
     * Store the release's single video artifact: a full-resolution stream-copy
     * Clip where possible, otherwise a capped browser-safe transcode. When the
     * Clip declines, no video artifact is stored and false is returned.
     */
    public function getVideo(string $fileLocation, string $tmpPath, string $guid, ?int $categoriesId = null): bool
    {
        if (! $this->config->processVideo || ! File::isFile($fileLocation)) {
            return false;
        }

        return $this->shouldAttemptClip($categoriesId) && $this->storeClip($fileLocation, $tmpPath, $guid);
    }

    /**
     * The Clip is opt-in per root category and disk-guarded: under the
     * Free-disk guard's threshold no video artifact is stored rather than
     * growing the covers volume.
     */
    private function shouldAttemptClip(?int $categoriesId): bool
    {
        return $categoriesId !== null
            && $this->clipPolicy->enabledForCategory($categoriesId)
            && $this->freeDiskGuard->allows($this->releaseImage->vidSavePath);
    }

    private function storeClip(string $fileLocation, string $tmpPath, string $guid): bool
    {
        $clip = $this->clipEncoder->encode(
            $fileLocation,
            $tmpPath,
            $this->ffmpegBinaryPath(),
            $this->config->timeoutSeconds > 0 ? $this->config->timeoutSeconds : 60,
            $this->config->previewTargetSeconds,
        );
        if ($clip === null) {
            return false;
        }

        // Duration floor: a starved extraction degrades to "no video preview"
        // rather than a seconds-long tease behind the play chip. An unreadable
        // duration is not "below the floor" and stores normally.
        if ($this->config->clipMinimumSeconds > 0
            && $clip->durationSeconds !== null
            && $clip->durationSeconds < $this->config->clipMinimumSeconds
        ) {
            File::delete($clip->path);

            return false;
        }

        if (! $this->storeGeneratedMedia($clip->path, $this->releaseImage->vidSavePath.$guid.'.'.$clip->extension)) {
            return false;
        }

        $this->removeSiblingVideoArtifacts($guid, $clip->extension);

        $releaseId = Release::query()->where('guid', $guid)->value('id');
        if ($releaseId !== null) {
            ReleaseVideoClip::query()->updateOrCreate(
                ['releases_id' => (int) $releaseId],
                [
                    'extension' => $clip->extension,
                    'mime' => $clip->mime,
                    'duration_seconds' => $clip->durationSeconds,
                    'bytes' => $clip->bytes,
                ],
            );
        }

        Release::query()->where('guid', $guid)->update(['videostatus' => 1]);

        return true;
    }

    /**
     * A release has one video artifact slot: whichever container was just
     * stored, any other container left over from an earlier run is stale.
     */
    private function removeSiblingVideoArtifacts(string $guid, string $keepExtension): void
    {
        foreach (array_keys(ReleaseVideoClip::VIDEO_MIME_TYPES) as $extension) {
            if ($extension !== $keepExtension) {
                File::delete($this->releaseImage->vidSavePath.$guid.'.'.$extension);
            }
        }
    }

    private function ffmpegBinaryPath(): string
    {
        return is_string($this->config->ffmpegPath) && $this->config->ffmpegPath !== ''
            ? $this->config->ffmpegPath
            : 'ffmpeg';
    }

    /**
     * Extract media info from a video file.
     */
    public function getMediaInfo(string $fileLocation, int $releaseId): bool
    {
        if (! $this->config->processMediaInfo || ! File::isFile($fileLocation)) {
            return false;
        }

        try {
            $xmlArray = $this->mediaInfo()->getInfo($fileLocation, true);
            if ($xmlArray->getVideos() === [] && $xmlArray->getAudios() === []) {
                return false;
            }
            \App\Models\MediaInfo::addData($releaseId, $xmlArray);
            $this->releaseExtra->addFromXml($releaseId, $xmlArray);
            $this->mediaInfoRefinement->refine($releaseId);

            return true;
        } catch (\Throwable $e) {
            Log::debug($e->getMessage());

            return false;
        }
    }

    /**
     * Process a JPG sample image.
     */
    public function getJPGSample(string $fileLocation, string $guid): bool
    {
        $saved = $this->releaseImage->saveExtractedImage(
            $guid,
            $fileLocation,
            $this->releaseImage->jpgSavePath,
            ImageAssetProfile::Sample,
        );

        if ($saved->success) {
            Release::query()->where('guid', $guid)->update(['jpgstatus' => 1]);

            return true;
        }

        return false;
    }

    /**
     * Process a video file for sample, video clip, and media info.
     *
     * @return array<string, mixed>
     */
    public function processVideoFile(
        string $fileLocation,
        ReleaseProcessingContext $context,
        string $tmpPath
    ): array {
        $result = [
            'sample' => false,
            'video' => false,
            'mediaInfo' => false,
        ];

        if (! $context->foundSample) {
            $result['sample'] = $this->getSample($fileLocation, $tmpPath, $context->release->guid);
            if ($result['sample']) {
                $context->foundSample = true;
            }
        }

        // Only get video if sampleMessageIDs count is less than 2
        if (! $context->foundVideo && count($context->sampleMessageIDs) < 2) {
            $result['video'] = $this->getVideo($fileLocation, $tmpPath, $context->release->guid, (int) $context->release->categories_id);
            if ($result['video']) {
                $context->foundVideo = true;
            }
        }

        if (! $context->foundMediaInfo) {
            $result['mediaInfo'] = $this->getMediaInfo($fileLocation, $context->release->id);
            if ($result['mediaInfo']) {
                $context->foundMediaInfo = true;
            }
        }

        return $result;
    }

    /**
     * Check if data appears to be a JPEG image.
     */
    public function isJpegData(string $filePath): bool
    {
        if (! File::isFile($filePath)) {
            return false;
        }

        return exif_imagetype($filePath) === IMAGETYPE_JPEG;
    }

    /**
     * Check if file is a valid image (JPEG or PNG).
     */
    public function isValidImage(string $filePath): bool
    {
        if (! File::isFile($filePath)) {
            return false;
        }

        $type = @exif_imagetype($filePath);

        return in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
    }

    /**
     * Move freshly generated ffmpeg output into its permanent home.
     *
     * ffmpeg creates the output path before it writes anything, so a clip that
     * starts past the end of the input leaves a 0-byte file behind. Treating
     * that as success filled the cover store with unplayable previews, so an
     * empty result is discarded instead of stored.
     */
    private function storeGeneratedMedia(string $sourcePath, string $destinationPath): bool
    {
        if (! File::isFile($sourcePath)) {
            return false;
        }

        if (File::size($sourcePath) <= 0) {
            File::delete($sourcePath);

            return false;
        }

        if (! @File::move($sourcePath, $destinationPath)) {
            $copied = @File::copy($sourcePath, $destinationPath);
            File::delete($sourcePath);
            if (! $copied) {
                return false;
            }
        }

        @chmod($destinationPath, 0764);

        return true;
    }

    private function mediaInfo(): MediaInfo
    {
        return $this->mediaTools()->mediaInfo();
    }

    private function mediaTools(): MediaTools
    {
        return $this->mediaTools ??= new MediaTools(
            $this->config->timeoutSeconds,
            $this->config->mediaInfoPath,
        );
    }
}
