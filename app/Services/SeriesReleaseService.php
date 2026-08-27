<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Release;
use App\Services\Releases\ReleaseBrowseService;
use App\Support\YearRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class SeriesReleaseService
{
    private const FALLBACK_SCAN_LIMIT = 500;

    public function __construct(
        private readonly EpisodeHydrationService $episodeHydrationService,
        private readonly ReleaseBrowseService $releaseBrowseService
    ) {}

    /**
     * @param  array<int, int>  $categories
     * @return array<int, int>
     */
    public function seasonCounts(int $videoId, array $categories, ?YearRange $yearRange = null): array
    {
        $query = $this->baseReleaseQuery($videoId, $categories)
            ->join('tv_episodes as tve', 'r.tv_episodes_id', '=', 'tve.id')
            ->where('tve.videos_id', $videoId);
        $this->applyYearRange($query, $yearRange);

        $counts = $query->selectRaw('tve.series as season, COUNT(*) as release_count')
            ->groupBy('tve.series')
            ->orderBy('tve.series')
            ->pluck('release_count', 'season')
            ->mapWithKeys(static fn ($count, $season): array => [(int) $season => (int) $count])
            ->all();

        foreach ($this->fallbackSeasonCounts($videoId, $categories, $yearRange) as $season => $count) {
            $counts[$season] = ($counts[$season] ?? 0) + $count;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<int, int>  $categories
     * @return array{releases:EloquentCollection<int, Release>, total:int}
     */
    public function releasesForSeason(
        int $videoId,
        int $season,
        int $offset,
        int $limit,
        array $categories,
        ?YearRange $yearRange = null,
    ): array {
        $matchedCount = $this->matchedSeasonCount($videoId, $season, $categories, $yearRange);
        $fallback = $this->fallbackReleasesForSeason($videoId, $season, $categories, $yearRange);
        $fallbackCount = $fallback->count();

        $matchedLimit = $limit;
        $matchedOffset = $offset;
        $fallbackSlice = new EloquentCollection;

        if ($limit > 0) {
            if ($offset >= $matchedCount) {
                $matchedLimit = 0;
                $matchedOffset = 0;
                $fallbackSlice = new EloquentCollection(
                    $fallback->slice($offset - $matchedCount, $limit)->values()->all()
                );
            } elseif (($offset + $limit) > $matchedCount) {
                $matchedLimit = $matchedCount - $offset;
                $fallbackLimit = $limit - $matchedLimit;
                $fallbackSlice = new EloquentCollection($fallback->take($fallbackLimit)->values()->all());
            }
        }

        $matched = $matchedLimit > 0
            ? $this->matchedSeasonReleaseQuery($videoId, $season, $categories, $yearRange)
                ->orderByDesc('r.postdate')
                ->limit($matchedLimit)
                ->offset($matchedOffset)
                ->get()
            : new EloquentCollection;

        $releases = new EloquentCollection($matched->concat($fallbackSlice)->values()->all());
        if ($releases->isNotEmpty()) {
            $releases->loadCount('failed');
        }

        return [
            'releases' => $releases,
            'total' => $matchedCount + $fallbackCount,
        ];
    }

    /**
     * @param  array<int, int>  $categories
     */
    private function matchedSeasonCount(int $videoId, int $season, array $categories, ?YearRange $yearRange): int
    {
        $query = $this->baseReleaseQuery($videoId, $categories)
            ->join('tv_episodes as tve', 'r.tv_episodes_id', '=', 'tve.id')
            ->where('tve.videos_id', $videoId)
            ->where('tve.series', $season);
        $this->applyYearRange($query, $yearRange);

        return $query->count();
    }

    /**
     * @param  array<int, int>  $categories
     * @return Builder<Release>
     */
    private function matchedSeasonReleaseQuery(int $videoId, int $season, array $categories, ?YearRange $yearRange): Builder
    {
        $query = $this->baseReleaseQuery($videoId, $categories)
            ->join('tv_episodes as tve', 'r.tv_episodes_id', '=', 'tve.id')
            ->where('tve.videos_id', $videoId)
            ->where('tve.series', $season);
        $this->applyYearRange($query, $yearRange);

        return $query->select([
            'r.id',
            'r.searchname',
            'r.display_name',
            'r.guid',
            'r.postdate',
            'r.groups_id',
            'r.categories_id',
            'r.size',
            'r.totalpart',
            'r.fromname',
            'r.passwordstatus',
            'r.grabs',
            'r.comments',
            'r.adddate',
            'r.videos_id',
            'r.tv_episodes_id',
            'tve.series',
            'tve.episode',
            'tve.firstaired',
        ]);
    }

    /**
     * @param  array<int, int>  $categories
     * @return Builder<Release>
     */
    private function baseReleaseQuery(int $videoId, array $categories): Builder
    {
        $query = Release::query()
            ->from('releases as r')
            ->where('r.videos_id', $videoId)
            ->whereRaw('r.passwordstatus '.$this->releaseBrowseService->showPasswords());

        if ($categories !== []) {
            $query->whereIn('r.categories_id', $categories);
        }

        return $query;
    }

    /**
     * @param  Builder<Release>  $query
     */
    private function applyYearRange(Builder $query, ?YearRange $yearRange): void
    {
        if (($startDate = $yearRange?->startDate()) !== null) {
            $query->where('tve.firstaired', '>=', $startDate);
        }
        if (($endDate = $yearRange?->endDate()) !== null) {
            $query->where('tve.firstaired', '<=', $endDate);
        }
    }

    /**
     * @param  array<int, int>  $categories
     * @return array<int, int>
     */
    private function fallbackSeasonCounts(int $videoId, array $categories, ?YearRange $yearRange): array
    {
        if ($yearRange !== null) {
            return [];
        }

        return $this->fallbackCandidates($videoId, $categories)
            ->groupBy(static fn (Release $release): int => (int) $release->getAttribute('series'))
            ->map(static fn (Collection $releases): int => $releases->count())
            ->all();
    }

    /**
     * @param  array<int, int>  $categories
     * @return EloquentCollection<int, Release>
     */
    private function fallbackReleasesForSeason(int $videoId, int $season, array $categories, ?YearRange $yearRange): EloquentCollection
    {
        if ($yearRange !== null) {
            return new EloquentCollection;
        }

        return new EloquentCollection(
            $this->fallbackCandidates($videoId, $categories)
                ->filter(static fn (Release $release): bool => (int) $release->getAttribute('series') === $season)
                ->values()
                ->all()
        );
    }

    /**
     * @param  array<int, int>  $categories
     * @return Collection<int, Release>
     */
    private function fallbackCandidates(int $videoId, array $categories): Collection
    {
        $candidates = $this->baseReleaseQuery($videoId, $categories)
            ->where(static function (Builder $query): void {
                $query->whereNull('r.tv_episodes_id')
                    ->orWhere('r.tv_episodes_id', '<=', 0);
            })
            ->select([
                'r.id',
                'r.searchname',
                'r.display_name',
                'r.guid',
                'r.postdate',
                'r.groups_id',
                'r.categories_id',
                'r.size',
                'r.totalpart',
                'r.fromname',
                'r.passwordstatus',
                'r.grabs',
                'r.comments',
                'r.adddate',
                'r.videos_id',
                'r.tv_episodes_id',
            ])
            ->orderByDesc('r.postdate')
            ->limit(self::FALLBACK_SCAN_LIMIT)
            ->get();

        $this->episodeHydrationService->hydrateEpisodeMetadata($candidates);

        return $candidates
            ->filter(static fn (Release $release): bool => $release->getAttribute('series') !== null && $release->getAttribute('series') !== '' && ! empty($release->getAttribute('episode')))
            ->values();
    }

    /**
     * @param  array<int, int|string>  $categoryInput
     * @return array<int, int>
     */
    public function categoryIds(array $categoryInput): array
    {
        $categories = Category::getCategorySearch($categoryInput, 'tv', true);

        return $categories === null ? [] : array_values(array_map('intval', $categories));
    }
}
