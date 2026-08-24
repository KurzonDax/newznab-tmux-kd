<?php

declare(strict_types=1);

namespace App\Services\Categorization;

use App\Models\AudioData;
use App\Models\Category;
use App\Models\Release;
use App\Models\VideoData;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\Releases\ForcedRootPolicy;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Support\ReleaseSearchIndexSync;
use Illuminate\Support\Facades\Log;

final class MediaInfoRefinementService
{
    /**
     * @param  array{containerformat?: mixed, videoformat?: mixed, videocodec?: mixed, videowidth?: mixed, videoheight?: mixed}|null  $video
     * @param  array{audioformat?: mixed}|null  $audio
     */
    public function decisionFor(int $currentCategoryId, ?array $video, ?array $audio): ?MediaInfoRefinementDecision
    {
        if (! in_array($currentCategoryId, self::eligibleCategoryIds(), true)) {
            return null;
        }

        if ($video !== null) {
            if ($currentCategoryId === Category::MUSIC_OTHER) {
                return new MediaInfoRefinementDecision(Category::MUSIC_VIDEO, 'video_music');
            }

            return $this->videoDecision($currentCategoryId, $video);
        }

        if ($audio === null) {
            return null;
        }

        $format = $this->normalized((string) ($audio['audioformat'] ?? ''));

        if ($format === 'MPEG AUDIO') {
            return new MediaInfoRefinementDecision(Category::MUSIC_MP3, 'audio_mpeg');
        }

        if (in_array($format, ['FLAC', 'ALAC', 'MONKEYS AUDIO', 'MONKEY S AUDIO', 'APE', 'WAVPACK', 'TTA', 'PCM'], true)) {
            return new MediaInfoRefinementDecision(Category::MUSIC_LOSSLESS, 'audio_lossless');
        }

        return new MediaInfoRefinementDecision(Category::MUSIC_OTHER, 'audio_other');
    }

    public function refine(int $releaseId, bool $dryRun = false): ?MediaInfoRefinementDecision
    {
        $release = Release::query()->find($releaseId, ['id', 'categories_id', 'groups_id']);
        if ($release === null || ! in_array((int) $release->categories_id, self::eligibleCategoryIds(), true)) {
            return null;
        }

        $video = VideoData::query()->where('releases_id', $releaseId)->first()?->toArray();
        $audio = AudioData::query()->where('releases_id', $releaseId)->orderBy('audioid')->first()?->toArray();
        $decision = $this->decisionFor((int) $release->categories_id, $video, $audio);

        if ($decision !== null && ! $this->respectsForcedRootCategory(
            (int) $release->groups_id,
            $releaseId,
            $decision->categoryId,
        )) {
            return null;
        }

        if ($decision === null || $decision->categoryId === (int) $release->categories_id || $dryRun) {
            return $decision;
        }

        $updated = Release::query()
            ->where('id', $releaseId)
            ->where('categories_id', $release->categories_id)
            ->update([
                'categories_id' => $decision->categoryId,
                'iscategorized' => 1,
            ]);

        if ($updated !== 1) {
            return null;
        }

        $this->previewPolicy->restoreOwedPreviews([$releaseId], false);
        $this->synchronize($releaseId);

        if (config('nntmux.categorization.log', false)) {
            Log::info('categorization.mediainfo_refined', [
                'release_id' => $releaseId,
                'old_category_id' => (int) $release->categories_id,
                'new_category_id' => $decision->categoryId,
                'rule' => $decision->rule,
            ]);
        }

        return $decision;
    }

    /**
     * A release pinned to a root by any associated group must stay in that root.
     *
     * The audio branch maps XXX_OTHER to MUSIC_*, which would quietly undo the
     * selected forced root; video decisions from XXX_OTHER already stay in-root.
     */
    private function respectsForcedRootCategory(int $groupId, int $releaseId, int $targetCategoryId): bool
    {
        if ($groupId <= 0) {
            return true;
        }

        $forcedRootCategoryId = $this->forcedRootPolicy->selectForRelease($groupId, $releaseId);

        if ($forcedRootCategoryId === null) {
            return true;
        }

        return Category::rootCategoryFor($targetCategoryId) === (int) $forcedRootCategoryId;
    }

    /**
     * @return list<int>
     */
    public static function eligibleCategoryIds(): array
    {
        return [
            Category::MOVIE_OTHER,
            Category::TV_OTHER,
            Category::XXX_OTHER,
            Category::MUSIC_OTHER,
        ];
    }

    /**
     * @param  array{containerformat?: mixed, videoformat?: mixed, videocodec?: mixed, videowidth?: mixed, videoheight?: mixed}  $video
     */
    private function videoDecision(int $currentCategoryId, array $video): ?MediaInfoRefinementDecision
    {
        $width = max(0, (int) ($video['videowidth'] ?? 0));
        $height = max(0, (int) ($video['videoheight'] ?? 0));
        $container = $this->normalized((string) ($video['containerformat'] ?? ''));
        $format = $this->normalized((string) ($video['videoformat'] ?? ''));
        $codec = $this->normalized((string) ($video['videocodec'] ?? ''));

        if ($width >= 3800 || $height >= 2100) {
            return new MediaInfoRefinementDecision(match ($currentCategoryId) {
                Category::MOVIE_OTHER => Category::MOVIE_UHD,
                Category::TV_OTHER => Category::TV_UHD,
                Category::XXX_OTHER => Category::XXX_UHD,
                default => throw new \LogicException('Unsupported video refinement category.'),
            }, 'video_uhd');
        }

        if ($container === 'MPEG PS' && $currentCategoryId !== Category::TV_OTHER) {
            return new MediaInfoRefinementDecision(
                $currentCategoryId === Category::MOVIE_OTHER ? Category::MOVIE_DVD : Category::XXX_DVD,
                'video_mpeg_ps',
            );
        }

        if ($container === 'BDAV' && $currentCategoryId === Category::MOVIE_OTHER) {
            return new MediaInfoRefinementDecision(Category::MOVIE_BLURAY, 'video_bdav');
        }

        if ($this->isHevc($format, $codec)) {
            return new MediaInfoRefinementDecision(match ($currentCategoryId) {
                Category::MOVIE_OTHER => Category::MOVIE_X265,
                Category::TV_OTHER => Category::TV_X265,
                Category::XXX_OTHER => Category::XXX_X264,
                default => throw new \LogicException('Unsupported video refinement category.'),
            }, 'video_hevc');
        }

        if ($currentCategoryId === Category::XXX_OTHER) {
            if ($format === 'VC 1' || $codec === 'VC 1' || str_starts_with($format, 'WMV') || str_starts_with($codec, 'WMV')) {
                return new MediaInfoRefinementDecision(Category::XXX_WMV, 'video_xxx_wmv');
            }

            if ($format === 'MPEG 4 VISUAL' || in_array($codec, ['XVID', 'DIVX', 'DX50'], true)) {
                return new MediaInfoRefinementDecision(Category::XXX_XVID, 'video_xxx_xvid');
            }
        }

        if ($width <= 0 && $height <= 0) {
            return null;
        }

        $isHighDefinition = $width >= 1280 || $height >= 720;

        return new MediaInfoRefinementDecision(match ($currentCategoryId) {
            Category::MOVIE_OTHER => $isHighDefinition ? Category::MOVIE_HD : Category::MOVIE_SD,
            Category::TV_OTHER => $isHighDefinition ? Category::TV_HD : Category::TV_SD,
            Category::XXX_OTHER => $isHighDefinition ? Category::XXX_X264 : Category::XXX_SD,
            default => throw new \LogicException('Unsupported video refinement category.'),
        }, $isHighDefinition ? 'video_hd' : 'video_sd');
    }

    private function isHevc(string $format, string $codec): bool
    {
        return str_contains($format, 'HEVC')
            || str_contains($format, 'H 265')
            || str_contains($codec, 'HEVC')
            || str_contains($codec, 'H 265');
    }

    private function normalized(string $value): string
    {
        return trim((string) preg_replace('/[^A-Z0-9]+/', ' ', strtoupper($value)));
    }

    public function __construct(
        private readonly PreviewGenerationPolicy $previewPolicy = new PreviewGenerationPolicy,
        private readonly ?ReleaseSearchSyncCoordinator $searchSyncCoordinator = null,
        private readonly ForcedRootPolicy $forcedRootPolicy = new ForcedRootPolicy,
    ) {}

    private function synchronize(int $releaseId): void
    {
        if ($this->searchSyncCoordinator !== null) {
            $this->searchSyncCoordinator->request($releaseId);

            return;
        }

        ReleaseSearchIndexSync::forIds([$releaseId]);
    }
}
