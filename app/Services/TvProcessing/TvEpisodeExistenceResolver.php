<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Models\TvEpisode;
use App\Models\Video;
use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\Providers\TmdbProvider;
use App\Services\TvProcessing\Providers\TraktProvider;
use App\Services\TvProcessing\Providers\TvdbProvider;
use App\Services\TvProcessing\Providers\TvMazeProvider;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Picks between same-named shows by the release's episode: a show is chosen only when it is the
 * one candidate with the season and episode stored and every other candidate is known to lack it.
 *
 * Known to lack is read only from a complete episode list, fetched from the candidate's provider
 * and cached for a day, and only when the release's season is past the end of that list. Stored
 * episode rows never prove absence: entries without a name, air date or overview are not stored.
 */
#[Singleton]
final class TvEpisodeExistenceResolver
{
    private const int LIST_CACHE_HOURS = 24;

    /** A season of at least this is a year, compared only with year-numbered seasons. */
    private const int YEAR_SEASON_MINIMUM = 1900;

    /** The provider and its id column for each videos.source. */
    private const array SOURCE_PROVIDERS = [
        1 => [TvdbProvider::class, 'tvdb'],
        2 => [TvMazeProvider::class, 'tvmaze'],
        3 => [TmdbProvider::class, 'tmdb'],
        4 => [TraktProvider::class, 'trakt'],
    ];

    /** @var array<string, true> Lists whose download was incomplete in this process. */
    private array $incompleteLists = [];

    /** @var array<class-string<AbstractTvProvider>, AbstractTvProvider> */
    private array $providers = [];

    /**
     * The candidate that alone has the release's episode, or 0 when the episode does not decide.
     *
     * @param  array<string, mixed>  $showInfo  parseInfo() of the release name
     * @param  list<int>  $candidateVideoIds
     */
    public function resolve(array $showInfo, array $candidateVideoIds): int
    {
        $season = (int) preg_replace('/^S0*/i', '', (string) ($showInfo['season'] ?? ''));
        $episode = (int) preg_replace('/^E0*/i', '', (string) ($showInfo['episode'] ?? ''));
        if ($season <= 0 || $episode <= 0 || count($candidateVideoIds) < 2) {
            return 0;
        }

        $holders = TvEpisode::query()
            ->whereIn('videos_id', $candidateVideoIds)
            ->where('series', $season)
            ->where('episode', $episode)
            ->distinct()
            ->pluck('videos_id')
            ->map(static fn (mixed $videoId): int => (int) $videoId)
            ->all();
        if (count($holders) !== 1) {
            return 0;
        }

        $holder = $holders[0];
        foreach ($candidateVideoIds as $videoId) {
            if ($videoId !== $holder && ! $this->isKnownToLack($videoId, $season)) {
                return 0;
            }
        }

        return $holder;
    }

    private function isKnownToLack(int $videoId, int $season): bool
    {
        $list = $this->completeEpisodeList($videoId);
        if ($list === null) {
            return false;
        }

        $yearNumbered = $season >= self::YEAR_SEASON_MINIMUM;
        $newestSeason = 0;
        foreach ($list as [$listedSeason]) {
            if (($listedSeason >= self::YEAR_SEASON_MINIMUM) === $yearNumbered) {
                $newestSeason = max($newestSeason, $listedSeason);
            }
        }

        if ($newestSeason === 0) {
            return false;
        }

        return $season > $newestSeason + 1
            || ($season === $newestSeason + 1 && TvEpisodeRevisitService::isFinished($videoId));
    }

    /**
     * The show's complete episode list from its provider, or null when none is available.
     *
     * @return list<array{int, int}>|null
     */
    private function completeEpisodeList(int $videoId): ?array
    {
        $video = Video::query()->whereKey($videoId)->first(['id', 'source', 'tvdb', 'tvmaze', 'tmdb', 'trakt']);
        if ($video === null) {
            return null;
        }

        $sourceProvider = self::SOURCE_PROVIDERS[(int) $video->source] ?? null;
        if ($sourceProvider === null) {
            return null;
        }

        [$providerClass, $idColumn] = $sourceProvider;
        $siteId = (int) $video->getAttribute($idColumn);
        if ($siteId <= 0) {
            return null;
        }

        $cacheKey = sprintf('tv:episode-list:%d:%s:%d', $videoId, $idColumn, $siteId);
        if (isset($this->incompleteLists[$cacheKey])) {
            return null;
        }

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            /** @var list<array{int, int}> $cached */
            return $cached;
        }

        try {
            $list = $this->provider($providerClass)->downloadEpisodeList($siteId, $videoId);
        } catch (Throwable $error) {
            Log::warning(sprintf('TV episode list download for video %d failed: %s', $videoId, $error->getMessage()));
            $list = null;
        }

        if ($list === null) {
            $this->incompleteLists[$cacheKey] = true;

            return null;
        }

        Cache::put($cacheKey, $list, now()->addHours(self::LIST_CACHE_HOURS));

        return $list;
    }

    /**
     * @param  class-string<AbstractTvProvider>  $providerClass
     */
    private function provider(string $providerClass): AbstractTvProvider
    {
        return $this->providers[$providerClass] ??= app($providerClass);
    }
}
