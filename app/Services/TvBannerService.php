<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ImageAssetProfile;
use App\Models\TvInfo;

final readonly class TvBannerService
{
    public function __construct(
        private FanartTvService $fanartTv,
        private ReleaseImageService $releaseImage,
    ) {}

    public function isConfigured(): bool
    {
        return $this->fanartTv->isConfigured();
    }

    public function fetch(int $videoId, int $tvdbId): bool
    {
        if ($videoId <= 0 || $tvdbId <= 0 || ! $this->isConfigured()) {
            return false;
        }

        $destinationDirectory = $this->destinationDirectory();
        $basename = $videoId.'-banner';
        $hasStoredBanner = (bool) TvInfo::query()
            ->where('videos_id', $videoId)
            ->value('banner');

        if ($hasStoredBanner && $this->releaseImage->imageExists($destinationDirectory, $basename)) {
            return true;
        }

        $properties = $this->fanartTv->getTvProperties($tvdbId);
        $bannerUrl = is_array($properties) ? ($properties['banner'] ?? null) : null;

        if (! is_string($bannerUrl) || $bannerUrl === '') {
            return false;
        }

        $result = $this->releaseImage->saveRemoteImage(
            $basename,
            $bannerUrl,
            $destinationDirectory,
            ImageAssetProfile::Original,
        );

        if (! $result->success) {
            return false;
        }

        TvInfo::markBannerAvailable($videoId);

        return true;
    }

    private function destinationDirectory(): string
    {
        $coversRoot = config('nntmux_settings.covers_path', storage_path('covers'));
        $coversRoot = is_string($coversRoot) && $coversRoot !== '' ? $coversRoot : storage_path('covers');

        return rtrim($coversRoot, '/\\').DIRECTORY_SEPARATOR.'tvshows'.DIRECTORY_SEPARATOR;
    }
}
