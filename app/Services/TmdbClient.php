<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\ProviderGateway\ProviderGateway;
use App\Services\ProviderGateway\ProviderOutcome;
use App\Services\ProviderGateway\ProviderResult;
use Illuminate\Support\Facades\Log;

/**
 * Custom TMDB (The Movie Database) API Client
 *
 * This service provides methods to interact with The Movie Database API
 * for fetching movie and TV show information.
 */
class TmdbClient
{
    protected const BASE_URL = 'https://api.themoviedb.org/3';

    protected const IMAGE_BASE_URL = 'https://image.tmdb.org/t/p';

    protected string $apiKey;

    protected int $timeout;

    public function __construct()
    {
        $this->apiKey = (string) config('tmdb.api_key', '');
        $this->timeout = (int) config('tmdb.timeout', 30);
    }

    /**
     * Check if the API key is configured
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Make a GET request to the TMDB API
     *
     * @param  string  $endpoint  The API endpoint
     * @param  array<string, mixed>  $params  Additional query parameters
     * @return array<string, mixed>|null Response data or null on failure
     */
    protected function get(string $endpoint, array $params = []): ?array
    {
        $result = $this->request($endpoint, $params);

        return is_array($result->value) ? $result->value : null;
    }

    /**
     * A GET request to the TMDB API through the provider gateway, which paces, retries and
     * breaker-guards it. An empty answer is NotFound, like a 404, and keeps its value.
     *
     * @param  array<string, mixed>  $params
     * @return ProviderResult<array<string, mixed>|null>
     */
    protected function request(string $endpoint, array $params = []): ProviderResult
    {
        if (! $this->isConfigured()) {
            Log::warning('TMDB API key is not configured');

            return ProviderResult::unavailable();
        }

        $params['api_key'] = $this->apiKey;
        $gateway = app(ProviderGateway::class);

        try {
            $response = $gateway->http('tmdb')
                ->timeout($this->timeout)
                ->get(self::BASE_URL.$endpoint, $params);
        } catch (\Throwable $e) {
            Log::warning('TMDB API request exception', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);

            return ProviderResult::unavailable($e);
        }

        if ($response->status() === 404) {
            Log::debug('TMDB: Resource not found', ['endpoint' => $endpoint]);

            return ProviderResult::notFound();
        }

        if (! $response->successful()) {
            Log::warning('TMDB API request failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return new ProviderResult($gateway->lastOutcome('tmdb') ?? ProviderOutcome::Unavailable);
        }

        $data = $response->json();
        if (! is_array($data) || $data === []) {
            $gateway->report('tmdb', ProviderOutcome::NotFound);

            return new ProviderResult(ProviderOutcome::NotFound, is_array($data) ? $data : null);
        }

        return ProviderResult::found($data);
    }

    /**
     * Get the full image URL
     *
     * @param  string|null  $path  The image path from TMDB
     * @param  string  $size  The image size (w92, w154, w185, w342, w500, w780, original)
     */
    public function getImageUrl(?string $path, string $size = 'w500'): string
    {
        if (empty($path)) {
            return '';
        }

        return self::IMAGE_BASE_URL.'/'.$size.$path;
    }

    // =========================================================================
    // MOVIE METHODS
    // =========================================================================

    /**
     * Search for movies by title
     *
     * @param  string  $query  The search query
     * @param  int  $page  Page number for pagination
     * @param  string|null  $year  Filter by release year
     * @return array<string, mixed>|null Search results or null on failure
     */
    public function searchMovies(string $query, int $page = 1, ?string $year = null): ?array
    {
        $params = [
            'query' => $query,
            'page' => $page,
            'include_adult' => false,
        ];

        if ($year !== null) {
            $params['year'] = $year;
        }

        return $this->get('/search/movie', $params);
    }

    /**
     * Get movie details by TMDB ID or IMDB ID
     *
     * @param  int|string  $id  The TMDB ID or IMDB ID (with 'tt' prefix)
     * @param  array<string, mixed>  $appendToResponse  Additional data to append (e.g., ['credits', 'external_ids'])
     * @return array<string, mixed>|null Movie data or null on failure
     */
    public function getMovie(int|string $id, array $appendToResponse = []): ?array
    {
        $result = $this->movie($id, $appendToResponse);

        return is_array($result->value) ? $result->value : null;
    }

    /**
     * A movie by TMDB ID or IMDb ID ('tt' prefix), with whether TMDB has no such movie
     * (NotFound) or could not be asked (Unavailable).
     *
     * @param  array<string, mixed>  $appendToResponse
     * @return ProviderResult<array<string, mixed>|null>
     */
    public function movie(int|string $id, array $appendToResponse = []): ProviderResult
    {
        if (is_string($id) && str_starts_with($id, 'tt')) {
            $found = $this->findMovie($id);
            if (! $found->isFound()) {
                return $found;
            }

            $tmdbId = self::getInt($found->value ?? [], 'id');
            if ($tmdbId <= 0) {
                return ProviderResult::notFound();
            }

            return $this->movie($tmdbId, $appendToResponse);
        }

        $params = [];

        if (! empty($appendToResponse)) {
            $params['append_to_response'] = implode(',', $appendToResponse);
        }

        return $this->request('/movie/'.$id, $params);
    }

    /**
     * Find a movie by external ID (IMDb, etc.)
     *
     * @param  string  $externalId  The external ID value
     * @param  string  $source  The source: 'imdb_id'
     * @return array<string, mixed>|null The TMDB movie data or null if not found
     */
    public function findMovieByExternalId(string $externalId, string $source = 'imdb_id'): ?array
    {
        $result = $this->findMovie($externalId, $source);

        return $result->isFound() ? $result->value : null;
    }

    /**
     * The first TMDB movie with this external ID, NotFound when TMDB knows none.
     *
     * @return ProviderResult<array<string, mixed>|null>
     */
    public function findMovie(string $externalId, string $source = 'imdb_id'): ProviderResult
    {
        if (empty($externalId) || $source !== 'imdb_id') {
            return ProviderResult::notFound();
        }

        $result = $this->request('/find/'.$externalId, [
            'external_source' => $source,
        ]);
        if (! $result->isFound()) {
            return $result;
        }

        $movie = $result->value['movie_results'][0] ?? null;

        return is_array($movie) && $movie !== [] ? ProviderResult::found($movie) : ProviderResult::notFound();
    }

    /**
     * Get movie credits (cast and crew)
     *
     * @param  int  $movieId  The TMDB movie ID
     * @return array<string, mixed>|null Credits data or null on failure
     */
    public function getMovieCredits(int $movieId): ?array
    {
        return $this->get('/movie/'.$movieId.'/credits');
    }

    /**
     * Get movie external IDs (IMDB, etc.)
     *
     * @param  int  $movieId  The TMDB movie ID
     * @return array<string, mixed>|null External IDs or null on failure
     */
    public function getMovieExternalIds(int $movieId): ?array
    {
        return $this->get('/movie/'.$movieId.'/external_ids');
    }

    /**
     * Get alternative titles for a movie (including international / alternate names).
     *
     * @param  int  $movieId  The TMDB movie ID
     * @return array<string, mixed>|null Response with 'titles' array (each has 'title', 'iso_3166_1', 'type') or null on failure
     */
    public function getMovieAlternativeTitles(int $movieId): ?array
    {
        return $this->get('/movie/'.$movieId.'/alternative_titles');
    }

    // =========================================================================
    // TV SHOW METHODS
    // =========================================================================

    /**
     * Search for TV shows by title
     *
     * @param  string  $query  The search query
     * @param  int  $page  Page number for pagination
     * @param  int|null  $firstAirDateYear  Filter by first air date year
     * @return array<string, mixed>|null Search results or null on failure
     */
    public function searchTv(string $query, int $page = 1, ?int $firstAirDateYear = null): ?array
    {
        $params = [
            'query' => $query,
            'page' => $page,
            'include_adult' => false,
        ];

        if ($firstAirDateYear !== null) {
            $params['first_air_date_year'] = $firstAirDateYear;
        }

        return $this->get('/search/tv', $params);
    }

    /**
     * Get TV show details by ID
     *
     * @param  int|string  $id  The TMDB TV show ID
     * @param  list<string>  $appendToResponse  Additional data to append
     * @return array<string, mixed>|null TV show data or null on failure
     */
    public function getTvShow(int|string $id, array $appendToResponse = []): ?array
    {
        $params = [];

        if (! empty($appendToResponse)) {
            $params['append_to_response'] = implode(',', $appendToResponse);
        }

        return $this->get('/tv/'.$id, $params);
    }

    /**
     * Get TV show external IDs (IMDB, TVDB, etc.)
     *
     * @param  int  $tvId  The TMDB TV show ID
     * @return array<string, mixed>|null External IDs or null on failure
     */
    public function getTvExternalIds(int $tvId): ?array
    {
        return $this->get('/tv/'.$tvId.'/external_ids');
    }

    /**
     * Get TV show alternative titles
     *
     * @param  int  $tvId  The TMDB TV show ID
     * @return array<string, mixed>|null Alternative titles or null on failure
     */
    public function getTvAlternativeTitles(int $tvId): ?array
    {
        return $this->get('/tv/'.$tvId.'/alternative_titles');
    }

    /**
     * Get TV season details
     *
     * @param  int  $tvId  The TMDB TV show ID
     * @param  int  $seasonNumber  The season number
     * @return array<string, mixed>|null Season data or null on failure
     */
    public function getTvSeason(int $tvId, int $seasonNumber): ?array
    {
        // Validate parameters to avoid unnecessary API calls with invalid IDs
        if ($tvId <= 0 || $seasonNumber < 0) {
            return null;
        }

        return $this->get('/tv/'.$tvId.'/season/'.$seasonNumber);
    }

    /**
     * Get TV episode details
     *
     * @param  int  $tvId  The TMDB TV show ID
     * @param  int  $seasonNumber  The season number
     * @param  int  $episodeNumber  The episode number
     * @return array<string, mixed>|null Episode data or null on failure
     */
    public function getTvEpisode(int $tvId, int $seasonNumber, int $episodeNumber): ?array
    {
        // Validate parameters to avoid unnecessary API calls with invalid IDs
        if ($tvId <= 0 || $seasonNumber < 0 || $episodeNumber < 0) {
            return null;
        }

        return $this->get('/tv/'.$tvId.'/season/'.$seasonNumber.'/episode/'.$episodeNumber);
    }

    /**
     * Find a TV show by external ID (IMDB, TVDB, etc.)
     *
     * @param  string  $externalId  The external ID value
     * @param  string  $source  The source: 'imdb_id', 'tvdb_id', 'tvrage_id'
     * @return array<string, mixed>|null The TMDB TV show data or null if not found
     */
    public function findTvByExternalId(string $externalId, string $source = 'tvdb_id'): ?array
    {
        if (empty($externalId)) {
            return null;
        }

        $validSources = ['imdb_id', 'tvdb_id', 'tvrage_id'];
        if (! in_array($source, $validSources, true)) {
            return null;
        }

        $result = $this->get('/find/'.$externalId, [
            'external_source' => $source,
        ]);

        if ($result === null || empty($result['tv_results'])) {
            return null;
        }

        // Return the first TV result
        return $result['tv_results'][0] ?? null;
    }

    /**
     * Get TV episode with fallback using multiple external IDs.
     * Tries TMDB ID first, then looks up by TVDB or IMDB if needed.
     *
     * @param  array<string, mixed>  $ids  Array of IDs: ['tmdb' => X, 'tvdb' => Y, 'imdb' => Z]
     * @param  int  $seasonNumber  The season number
     * @param  int  $episodeNumber  The episode number
     * @return array<string, mixed>|null Episode data or null on failure
     */
    public function getTvEpisodeWithFallback(array $ids, int $seasonNumber, int $episodeNumber): ?array
    {
        // Each TMDB show is asked once, however many of the IDs lead to it.
        $asked = [];
        $episodeOf = function (int $showId) use (&$asked, $seasonNumber, $episodeNumber): ?array {
            if ($showId <= 0 || isset($asked[$showId])) {
                return null;
            }
            $asked[$showId] = true;

            return $this->getTvEpisode($showId, $seasonNumber, $episodeNumber);
        };

        // First try with TMDB ID if available
        $result = $episodeOf((int) ($ids['tmdb'] ?? 0));
        if ($result !== null) {
            return $result;
        }

        // Try to find the show by TVDB ID
        $tvdbId = (int) ($ids['tvdb'] ?? 0);
        if ($tvdbId > 0) {
            $show = $this->findTvByExternalId((string) $tvdbId, 'tvdb_id');
            if ($show !== null && isset($show['id'])) {
                $result = $episodeOf((int) $show['id']);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        // Try to find the show by IMDB ID
        $imdbId = $ids['imdb'] ?? '';
        if (! empty($imdbId)) {
            $imdbFormatted = str_starts_with((string) $imdbId, 'tt')
                ? (string) $imdbId
                : 'tt'.(string) $imdbId;

            $show = $this->findTvByExternalId($imdbFormatted, 'imdb_id');
            if ($show !== null && isset($show['id'])) {
                $result = $episodeOf((int) $show['id']);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Look up a TV show and get all its external IDs.
     * Can be used to cross-reference between providers.
     *
     * @param  int|string  $id  The external ID
     * @param  string  $source  The ID source: 'tmdb', 'tvdb', 'imdb'
     * @return array<string, mixed>|null Array with all IDs: ['tmdb' => X, 'imdb' => Y, 'tvdb' => Z] or null
     */
    public function lookupTvShowIds(int|string $id, string $source = 'tmdb'): ?array
    {
        $show = null;

        if ($source === 'tmdb' && is_numeric($id) && (int) $id > 0) {
            // Direct TMDB lookup
            $show = $this->getTvShow((int) $id);
            if ($show !== null) {
                $externalIds = $this->getTvExternalIds((int) $id);
                if ($externalIds !== null) {
                    $show['external_ids'] = $externalIds;
                }
            }
        } elseif ($source === 'tvdb' && is_numeric($id) && (int) $id > 0) {
            $show = $this->findTvByExternalId((string) $id, 'tvdb_id');
        } elseif ($source === 'imdb') {
            $imdbFormatted = str_starts_with((string) $id, 'tt')
                ? (string) $id
                : 'tt'.(string) $id;
            $show = $this->findTvByExternalId($imdbFormatted, 'imdb_id');
        }

        if ($show === null) {
            return null;
        }

        // If we found by external ID, we need to fetch external IDs for full data
        $tmdbId = self::getInt($show, 'id');
        if ($tmdbId > 0 && ! isset($show['external_ids'])) {
            $externalIds = $this->getTvExternalIds($tmdbId);
            $show['external_ids'] = $externalIds ?? [];
        }

        $externalIds = self::getArray($show, 'external_ids');

        // Parse IMDB ID to raw numeric string
        $imdbId = '';
        if (! empty($externalIds['imdb_id'])) {
            preg_match('/tt(?P<imdbid>\d{6,})$/i', (string) $externalIds['imdb_id'], $imdb);
            $imdbId = (string) ($imdb['imdbid'] ?? '');
        }

        return [
            'tmdb' => $tmdbId,
            'imdb' => $imdbId,
            'tvdb' => self::getInt($externalIds, 'tvdb_id'),
            'tvrage' => self::getInt($externalIds, 'tvrage_id'),
        ];
    }

    // =========================================================================
    // HELPER METHODS FOR NULL-SAFE DATA EXTRACTION
    // =========================================================================

    /**
     * Safely get a string value from an array
     *
     * @param  array<string, mixed>  $data
     */
    public static function getString(array $data, string $key, string $default = ''): string
    {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : $default;
    }

    /**
     * Safely get an integer value from an array
     *
     * @param  array<string, mixed>  $data
     */
    public static function getInt(array $data, string $key, int $default = 0): int
    {
        return isset($data[$key]) && is_numeric($data[$key]) ? (int) $data[$key] : $default;
    }

    /**
     * Safely get a float value from an array
     *
     * @param  array<string, mixed>  $data
     */
    public static function getFloat(array $data, string $key, float $default = 0.0): float
    {
        return isset($data[$key]) && is_numeric($data[$key]) ? (float) $data[$key] : $default;
    }

    /**
     * Safely get an array value from an array
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public static function getArray(array $data, string $key, array $default = []): array
    {
        return isset($data[$key]) && is_array($data[$key]) ? $data[$key] : $default;
    }

    /**
     * Safely get a nested value from an array using dot notation
     *
     * @param  array<string, mixed>  $data
     */
    public static function getNested(array $data, string $path, mixed $default = null): mixed
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value ?? $default;
    }
}
