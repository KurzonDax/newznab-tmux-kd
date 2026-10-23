<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\ProviderGateway\ProviderGateway;
use App\Services\ProviderGateway\ProviderOutcome;
use App\Services\ProviderGateway\ProviderResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Trakt.tv Service
 *
 * A modern service wrapper for the Trakt.tv API.
 * Provides methods to fetch movie and TV show information.
 *
 * API Documentation: https://docs.trakt.tv/
 *
 * This wrapper intentionally uses public metadata endpoints only. OAuth should
 * only be added if a future feature needs user-scoped Trakt data such as
 * watchlists, watched history, private lists, ratings, or sync endpoints.
 */
class TraktService
{
    protected const BASE_URL = 'https://api.trakt.tv';

    protected const CACHE_TTL_HOURS = 24;

    protected const API_VERSION = 2;

    protected const ID_TYPES = ['imdb', 'tmdb', 'trakt', 'tvdb'];

    protected const SEARCH_TYPES = ['movie', 'show', 'episode', 'person', 'list'];

    protected string $clientId;

    protected int $timeout;

    public function __construct(?string $clientId = null)
    {
        $this->clientId = trim($clientId ?? (string) config('nntmux_api.trakttv_api_key', ''));
        $this->timeout = (int) config('nntmux_api.trakttv_timeout', 30);
    }

    /**
     * Check if the API key is configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->clientId);
    }

    /**
     * Get the request headers for Trakt API.
     *
     * @return array<string, mixed>
     */
    protected function getHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'trakt-api-version' => (string) self::API_VERSION,
            'trakt-api-key' => $this->clientId,
        ];
    }

    /**
     * Make a GET request to the Trakt API.
     *
     * @param  string  $endpoint  The API endpoint
     * @param  array<string, mixed>  $params  Query parameters
     * @return array<string, mixed>|null Response data or null on failure
     */
    protected function get(string $endpoint, array $params = []): ?array
    {
        $result = $this->request($endpoint, $params);

        return is_array($result->value) ? $result->value : null;
    }

    /**
     * A GET request to the Trakt API through the provider gateway, which spaces requests a
     * second apart across workers, retries transient failures and honours Retry-After.
     * An empty list is NotFound and keeps its value.
     *
     * @param  array<string, mixed>  $params
     * @return ProviderResult<array<string, mixed>|null>
     */
    public function request(string $endpoint, array $params = []): ProviderResult
    {
        if (! $this->isConfigured()) {
            Log::debug('Trakt API key is not configured');

            return ProviderResult::unavailable();
        }

        $gateway = app(ProviderGateway::class);

        try {
            $response = $gateway->http('trakt')
                ->timeout($this->timeout)
                ->withHeaders($this->getHeaders())
                ->get(self::BASE_URL.'/'.ltrim($endpoint, '/'), $params);
        } catch (\Throwable $e) {
            Log::warning('Trakt API request exception', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);

            return ProviderResult::unavailable($e);
        }

        if ($response->successful()) {
            $data = $response->json();

            // Check for API error responses
            if (isset($data['status']) && $data['status'] === 'failure') {
                Log::debug('Trakt API returned failure status', [
                    'endpoint' => $endpoint,
                ]);
                $gateway->report('trakt', ProviderOutcome::NotFound);

                return ProviderResult::notFound();
            }

            if (! is_array($data) || $data === []) {
                $gateway->report('trakt', ProviderOutcome::NotFound);

                return new ProviderResult(ProviderOutcome::NotFound, is_array($data) ? $data : null);
            }

            return ProviderResult::found($data);
        }

        // Handle 404 - normal (resource not found)
        if ($response->status() === 404) {
            Log::debug('Trakt: Resource not found', ['endpoint' => $endpoint]);

            return ProviderResult::notFound();
        }

        Log::warning($response->status() === 429 ? 'Trakt API rate limited (429)' : 'Trakt API request failed', [
            'endpoint' => $endpoint,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return new ProviderResult($gateway->lastOutcome('trakt') ?? ProviderOutcome::Unavailable);
    }

    /**
     * Get episode summary using multiple ID types for fallback.
     *
     * @param  int|string  $showId  The show ID (Trakt, IMDB, TMDB, or TVDB)
     * @param  int|string  $season  The season number
     * @param  int|string  $episode  The episode number
     * @param  string  $extended  Extended info level: 'min', 'full', 'aliases', 'full,aliases'
     * @param  string  $idType  The ID type: 'trakt', 'imdb', 'tmdb', 'tvdb' (default: 'trakt')
     * @return array<string, mixed>|null Episode data or null on failure
     */
    public function getEpisodeSummary(
        int|string $showId,
        int|string $season,
        int|string $episode,
        string $extended = 'min',
        string $idType = 'trakt'
    ): ?array {
        // Validate parameters to avoid unnecessary API calls with invalid IDs
        $showIdInt = is_numeric($showId) ? (int) $showId : 0;
        $seasonInt = is_numeric($season) ? (int) $season : -1;
        $episodeInt = is_numeric($episode) ? (int) $episode : -1;

        // For numeric show IDs, validate they are positive
        // Season and episode must be non-negative (season 0 can be specials)
        if (($showIdInt <= 0 && is_numeric($showId)) || $seasonInt < 0 || $episodeInt < 0) {
            return null;
        }

        $extended = $this->normalizeExtended($extended, ['min', 'full', 'aliases', 'full,aliases']);

        // Format the show ID based on type for cache key and API call
        $formattedId = $this->formatShowIdForApi($showId, $idType);
        if ($formattedId === null) {
            return null;
        }

        $cacheKey = "trakt_episode_{$idType}_{$formattedId}_{$season}_{$episode}_{$extended}";

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($formattedId, $season, $episode, $extended) {
            return $this->get("shows/{$formattedId}/seasons/{$season}/episodes/{$episode}", [
                'extended' => $extended,
            ]);
        });
    }

    /**
     * Get episode summary with fallback to multiple ID types.
     * Tries Trakt ID first, then falls back to TMDB, TVDB, and IMDB.
     *
     * @param  array<string, mixed>  $ids  Array of IDs: ['trakt' => X, 'tmdb' => Y, 'tvdb' => Z, 'imdb' => W]
     * @param  int|string  $season  The season number
     * @param  int|string  $episode  The episode number
     * @param  string  $extended  Extended info level
     * @return array<string, mixed>|null Episode data or null on failure
     */
    public function getEpisodeSummaryWithFallback(
        array $ids,
        int|string $season,
        int|string $episode,
        string $extended = 'min'
    ): ?array {
        // Priority order for ID types
        $idPriority = ['trakt', 'tmdb', 'tvdb', 'imdb'];

        foreach ($idPriority as $idType) {
            $id = $ids[$idType] ?? null;
            if ($this->isUsableExternalId($id, $idType)) {
                $result = $this->getEpisodeSummary($id, $season, $episode, $extended, $idType);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Format show ID for Trakt API based on ID type.
     * Trakt API accepts different ID formats for lookups.
     *
     * @param  int|string  $id  The show ID
     * @param  string  $idType  The ID type: 'trakt', 'imdb', 'tmdb', 'tvdb'
     * @return string|null Formatted ID for API call or null if invalid
     */
    protected function formatShowIdForApi(int|string $id, string $idType): ?string
    {
        if (! in_array($idType, self::ID_TYPES, true)) {
            return null;
        }

        $formattedId = trim((string) $id);
        if ($formattedId === '') {
            return null;
        }

        if ($idType === 'imdb') {
            return $this->formatImdbId($formattedId);
        }

        return $formattedId;
    }

    /**
     * Get show summary with all external IDs.
     * This enriches the response with IDs from all supported providers.
     *
     * @param  int|string  $showId  Show ID
     * @param  string  $idType  The ID type: 'trakt', 'imdb', 'tmdb', 'tvdb'
     * @return array<string, mixed>|null Show data with all IDs or null on failure
     */
    public function getShowWithAllIds(int|string $showId, string $idType = 'trakt'): ?array
    {
        $formattedId = $this->formatShowIdForApi($showId, $idType);
        if ($formattedId === null) {
            return null;
        }

        $cacheKey = "trakt_show_ids_{$idType}_{$showId}";

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($formattedId) {
            return $this->get("shows/{$formattedId}", ['extended' => 'full']);
        });
    }

    /**
     * Look up a show by external ID and return all available IDs.
     * Useful for cross-referencing between TMDB, TVDB, Trakt, and IMDB.
     *
     * @param  int|string  $id  The external ID
     * @param  string  $idType  The ID type: 'imdb', 'tmdb', 'tvdb'
     * @return array<string, mixed>|null Array with all IDs: ['trakt' => X, 'imdb' => Y, 'tmdb' => Z, 'tvdb' => W] or null
     */
    public function lookupShowIds(int|string $id, string $idType = 'tmdb'): ?array
    {
        if (! in_array($idType, self::ID_TYPES, true)) {
            return null;
        }

        // Use the search endpoint to find the show by external ID
        $results = $this->searchById($id, $idType, 'show');

        if (empty($results)) {
            return null;
        }

        // Get the first matching show
        $show = $results[0]['show'] ?? null;
        if ($show === null || ! isset($show['ids'])) { // @phpstan-ignore isset.offset
            return null;
        }

        return [
            'trakt' => $show['ids']['trakt'] ?? 0,
            'imdb' => $show['ids']['imdb'] ?? null,
            'tmdb' => $show['ids']['tmdb'] ?? 0,
            'tvdb' => $show['ids']['tvdb'] ?? 0,
            'tvrage' => $show['ids']['tvrage'] ?? 0,
            'slug' => $show['ids']['slug'] ?? '',
        ];
    }

    /**
     * Get current box office movies.
     *
     * @return array<string, mixed>|null Box office data or null on failure
     */
    public function getBoxOffice(): ?array
    {
        $cacheKey = 'trakt_boxoffice_'.date('Y-m-d');

        return Cache::remember($cacheKey, now()->addHours(6), function () {
            return $this->get('movies/boxoffice');
        });
    }

    /**
     * Get TV show calendar.
     *
     * @param  string  $startDate  Start date (YYYY-MM-DD format, defaults to today)
     * @param  int  $days  Number of days to retrieve (default: 7)
     * @return array<string, mixed>|null Calendar data or null on failure
     */
    public function getCalendar(string $startDate = '', int $days = 7): ?array
    {
        if (empty($startDate)) {
            $startDate = date('Y-m-d');
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            return null;
        }

        $days = max(1, min($days, 33));

        $cacheKey = "trakt_calendar_{$startDate}_{$days}";

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($startDate, $days) {
            return $this->get("calendars/all/shows/{$startDate}/{$days}");
        });
    }

    /**
     * Get movie summary.
     *
     * @param  string  $movie  Movie slug or ID
     * @param  string  $extended  Extended info level: 'min' or 'full'
     * @return array<string, mixed>|null Movie data or null on failure
     */
    public function getMovieSummary(string $movie, string $extended = 'min'): ?array
    {
        if (empty($movie)) {
            return null;
        }

        $extended = $this->normalizeExtended($extended, ['min', 'full']);
        $movieId = $this->formatMediaId($movie);
        if ($movieId === null) {
            return null;
        }

        $cacheKey = 'trakt_movie_'.md5($movieId).'_'.$extended;

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($movieId, $extended) {
            return $this->get("movies/{$movieId}", ['extended' => $extended]);
        });
    }

    /**
     * Get IMDB ID for a movie.
     *
     * @param  string  $movie  Movie slug or ID
     * @return string|null IMDB ID or null on failure
     */
    public function getMovieImdbId(string $movie): ?string
    {
        $data = $this->getMovieSummary($movie, 'min');

        return $data['ids']['imdb'] ?? null;
    }

    /**
     * Search by external ID.
     *
     * @param  int|string  $id  The ID to search for
     * @param  string  $idType  The ID type: 'imdb', 'tmdb', 'trakt', 'tvdb'
     * @param  string  $mediaType  Media type: 'movie', 'show', 'episode', or empty for all
     * @return array<string, mixed>|null Search results or null on failure
     */
    public function searchById(int|string $id, string $idType = 'trakt', string $mediaType = ''): ?array
    {
        if (! in_array($idType, self::ID_TYPES, true)) {
            Log::warning('Trakt: Invalid ID type', ['idType' => $idType]);

            return null;
        }

        $formattedId = trim((string) $id);
        if ($formattedId === '') {
            return null;
        }

        if ($idType === 'imdb') {
            $formattedId = $this->formatImdbId($formattedId);
            if ($formattedId === null) {
                return null;
            }
        }

        $params = [
            'id_type' => $idType,
            'id' => $formattedId,
        ];

        if ($mediaType !== '' && ! in_array($mediaType, self::SEARCH_TYPES, true)) {
            return null;
        }

        if ($mediaType !== '') {
            $params['type'] = $mediaType;
        }

        $cacheKey = 'trakt_search_'.$idType.'_'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $formattedId).'_'.($params['type'] ?? 'all');

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($formattedId, $idType, $params) {
            return $this->get('search/'.$idType.'/'.$formattedId, array_intersect_key($params, ['type' => true]));
        });
    }

    /**
     * Search for a show by name.
     *
     * @param  string  $query  Search query
     * @param  string  $type  Search type: 'show', 'movie', 'episode', 'person', 'list'
     * @return array<string, mixed>|null Search results or null on failure
     */
    public function searchShows(string $query, string $type = 'show'): ?array
    {
        $query = trim($query);
        if ($query === '' || ! in_array($type, self::SEARCH_TYPES, true)) {
            return null;
        }

        $slug = Str::slug($query);
        $cacheKey = "trakt_search_{$type}_{$slug}";

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($query, $type) {
            return $this->get('search/'.$type, ['query' => $query]);
        });
    }

    /**
     * Get show summary.
     *
     * @param  int|string  $show  Show slug or ID
     * @param  string  $extended  Extended info level: 'min' or 'full'
     * @return array<string, mixed>|null Show data or null on failure
     */
    public function getShowSummary(int|string $show, string $extended = 'full'): ?array
    {
        $showKey = trim((string) $show);

        if ($showKey === '') {
            return null;
        }

        $extended = $this->normalizeExtended($extended, ['min', 'full']);
        $showId = $this->formatMediaId($showKey);
        if ($showId === null) {
            return null;
        }

        $cacheKey = 'trakt_show_'.md5($showId).'_'.$extended;

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($showId, $extended) {
            return $this->get("shows/{$showId}", ['extended' => $extended]);
        });
    }

    /**
     * Get show seasons.
     *
     * @param  string  $show  Show slug or ID
     * @param  string  $extended  Extended info level
     * @return array<string, mixed>|null Seasons data or null on failure
     */
    public function getShowSeasons(string $show, string $extended = 'full'): ?array
    {
        $showId = $this->formatMediaId($show);
        if ($showId === null) {
            return null;
        }

        $extended = $this->normalizeExtended($extended, ['min', 'full', 'episodes', 'full,episodes']);
        $cacheKey = 'trakt_seasons_'.md5($showId).'_'.$extended;

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($showId, $extended) {
            return $this->get("shows/{$showId}/seasons", ['extended' => $extended]);
        });
    }

    /**
     * Get season episodes.
     *
     * @param  string  $show  Show slug or ID
     * @param  int  $season  Season number
     * @param  string  $extended  Extended info level
     * @return array<string, mixed>|null Episodes data or null on failure
     */
    public function getSeasonEpisodes(string $show, int $season, string $extended = 'full'): ?array
    {
        $showId = $this->formatMediaId($show);
        if ($showId === null || $season < 0) {
            return null;
        }

        $extended = $this->normalizeExtended($extended, ['min', 'full']);
        $cacheKey = 'trakt_season_episodes_'.md5($showId)."_{$season}_{$extended}";

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($showId, $season, $extended) {
            return $this->get("shows/{$showId}/seasons/{$season}", ['extended' => $extended]);
        });
    }

    /**
     * Get trending shows.
     *
     * @param  int  $limit  Number of results to return
     * @param  string  $extended  Extended info level
     * @return array<string, mixed>|null Trending shows or null on failure
     */
    public function getTrendingShows(int $limit = 10, string $extended = 'full'): ?array
    {
        $limit = $this->normalizeLimit($limit);
        $extended = $this->normalizeExtended($extended, ['min', 'full']);
        $cacheKey = "trakt_trending_shows_{$limit}_{$extended}";

        return Cache::remember($cacheKey, now()->addHours(1), function () use ($limit, $extended) {
            return $this->get('shows/trending', [
                'limit' => $limit,
                'extended' => $extended,
            ]);
        });
    }

    /**
     * Get trending movies.
     *
     * @param  int  $limit  Number of results to return
     * @param  string  $extended  Extended info level
     * @return array<string, mixed>|null Trending movies or null on failure
     */
    public function getTrendingMovies(int $limit = 10, string $extended = 'full'): ?array
    {
        $limit = $this->normalizeLimit($limit);
        $extended = $this->normalizeExtended($extended, ['min', 'full']);
        $cacheKey = "trakt_trending_movies_{$limit}_{$extended}";

        return Cache::remember($cacheKey, now()->addHours(1), function () use ($limit, $extended) {
            return $this->get('movies/trending', [
                'limit' => $limit,
                'extended' => $extended,
            ]);
        });
    }

    /**
     * Get popular shows.
     *
     * @param  int  $limit  Number of results to return
     * @param  string  $extended  Extended info level
     * @return array<string, mixed>|null Popular shows or null on failure
     */
    public function getPopularShows(int $limit = 10, string $extended = 'full'): ?array
    {
        $limit = $this->normalizeLimit($limit);
        $extended = $this->normalizeExtended($extended, ['min', 'full']);
        $cacheKey = "trakt_popular_shows_{$limit}_{$extended}";

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($limit, $extended) {
            return $this->get('shows/popular', [
                'limit' => $limit,
                'extended' => $extended,
            ]);
        });
    }

    /**
     * Get popular movies.
     *
     * @param  int  $limit  Number of results to return
     * @param  string  $extended  Extended info level
     * @return array<string, mixed>|null Popular movies or null on failure
     */
    public function getPopularMovies(int $limit = 10, string $extended = 'full'): ?array
    {
        $limit = $this->normalizeLimit($limit);
        $extended = $this->normalizeExtended($extended, ['min', 'full']);
        $cacheKey = "trakt_popular_movies_{$limit}_{$extended}";

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($limit, $extended) {
            return $this->get('movies/popular', [
                'limit' => $limit,
                'extended' => $extended,
            ]);
        });
    }

    /**
     * Clear cached data for a specific show.
     */
    public function clearShowCache(string $show): void
    {
        $slug = Str::slug($show);
        Cache::forget("trakt_show_{$slug}_min");
        Cache::forget("trakt_show_{$slug}_full");
        Cache::forget("trakt_seasons_{$slug}_full");
        Cache::forget("trakt_seasons_{$slug}_min");

        $showId = $this->formatMediaId($show);
        if ($showId !== null) {
            Cache::forget('trakt_show_'.md5($showId).'_min');
            Cache::forget('trakt_show_'.md5($showId).'_full');
            Cache::forget('trakt_seasons_'.md5($showId).'_min');
            Cache::forget('trakt_seasons_'.md5($showId).'_full');
            Cache::forget('trakt_seasons_'.md5($showId).'_episodes');
            Cache::forget('trakt_seasons_'.md5($showId).'_full,episodes');
        }
    }

    /**
     * Clear cached data for a specific movie.
     */
    public function clearMovieCache(string $movie): void
    {
        $slug = Str::slug($movie);
        Cache::forget("trakt_movie_{$slug}_min");
        Cache::forget("trakt_movie_{$slug}_full");

        $movieId = $this->formatMediaId($movie);
        if ($movieId !== null) {
            Cache::forget('trakt_movie_'.md5($movieId).'_min');
            Cache::forget('trakt_movie_'.md5($movieId).'_full');
        }
    }

    /**
     * Normalize IMDb IDs for Trakt endpoints without padding away meaningful
     * leading zeroes (for example, tt0137523 must stay tt0137523).
     */
    protected function formatImdbId(string $id): ?string
    {
        if (preg_match('/^tt\d{6,}$/i', $id) === 1) {
            return $id;
        }

        $digits = preg_replace('/\D/', '', $id);

        return $digits !== null && preg_match('/^\d{6,}$/', $digits) === 1 ? 'tt'.$digits : null;
    }

    protected function formatMediaId(int|string $id): ?string
    {
        $id = trim((string) $id);
        if ($id === '') {
            return null;
        }

        return str_starts_with(strtolower($id), 'tt') ? $this->formatImdbId($id) : Str::slug($id);
    }

    /**
     * @param  list<string>  $allowed
     */
    protected function normalizeExtended(string $extended, array $allowed): string
    {
        return in_array($extended, $allowed, true) ? $extended : $allowed[0];
    }

    protected function normalizeLimit(int $limit): int
    {
        return max(1, min($limit, 100));
    }

    protected function isUsableExternalId(mixed $id, string $idType): bool
    {
        if ($id === null || $id === '') {
            return false;
        }

        if ($idType === 'imdb') {
            return $this->formatImdbId((string) $id) !== null;
        }

        return is_numeric($id) ? (int) $id > 0 : trim((string) $id) !== '';
    }
}
