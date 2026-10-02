<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\IGDB\Exceptions\IgdbHttpException;
use App\Services\IGDB\Models\Company;
use App\Services\IGDB\Models\Game;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * IGDBService - IGDB (Internet Game Database) API integration.
 *
 * Note: Local IGDB resource models expose dynamic properties
 * (name, id, etc.) that PHPStan cannot fully resolve.
 *
 * Features:
 * - Rate limiting and caching
 * - Multiple search strategies for better matching
 * - Complete game metadata retrieval
 * - Age rating and release date extraction
 */
class IGDBService
{
    // Rate limiting constants
    protected const string RATE_LIMIT_KEY = 'igdb_api_rate_limit';

    protected const int REQUESTS_PER_MINUTE = 4;

    protected const int DECAY_SECONDS = 60;

    // Cache TTLs
    protected const int GAME_CACHE_TTL = 86400; // 24 hours

    protected const int FAILED_LOOKUP_CACHE_TTL = 3600; // 1 hour

    // Bumped when the requested fields or search filters change, so neither a Game
    // cached without the new fields nor a failure cached by an old filter is reused.
    protected const string SEARCH_CACHE_VERSION = 'v3';

    // Matching configuration
    protected const int MATCH_THRESHOLD = 85;

    /** IGDB's website type for a game's official website. */
    protected const int OFFICIAL_WEBSITE_TYPE = 1;

    /** Length of consoleinfo.website. */
    protected const int WEBSITE_LENGTH = 1000;

    // PC Platform IDs in IGDB
    protected const array PC_PLATFORM_IDS = [6, 13, 14, 3]; // PC Windows, DOS, Mac, Linux

    /**
     * Company names by IGDB company id, kept for one buildConsoleData() or buildGameData() call.
     *
     * @var array<int, string|null>
     */
    private array $companyNames = [];

    protected const array PLATFORM_ALIASES = [
        'x360' => 'xbox 360',
        'xbox360' => 'xbox 360',
        'xbla' => 'xbox 360',
        'xbox one' => 'xbox one',
        'xboxone' => 'xbox one',
        'x-box' => 'xbox',
        'xbox' => 'xbox',
        'dsi' => 'nintendo ds',
        'nds' => 'nintendo ds',
        '3ds' => 'nintendo 3ds',
        'ps2' => 'playstation2',
        'ps3' => 'playstation 3',
        'ps4' => 'playstation 4',
        'psp' => 'sony psp',
        'psvita' => 'playstation vita',
        'psx' => 'playstation',
        'psx2psp' => 'playstation',
        'wiiu' => 'nintendo wii u',
        'wii' => 'nintendo wii',
        'ngc' => 'gamecube',
        'gc' => 'gamecube',
        'n64' => 'nintendo 64',
        'nes' => 'nintendo nes',
        'super nintendo' => 'snes',
        'nintendo super nes' => 'snes',
        'snes' => 'snes',
    ];

    /**
     * Check if IGDB is configured.
     */
    public function isConfigured(): bool
    {
        return config('igdb.credentials.client_id') !== ''
            && config('igdb.credentials.client_secret') !== '';
    }

    /**
     * Search for a game by title and return the best matching Game object.
     */
    public function search(string $title): ?Game
    {
        if (! $this->isConfigured() || empty($title)) {
            return null;
        }

        $cacheKey = 'igdb_search:'.self::SEARCH_CACHE_VERSION.':'.md5(mb_strtolower($title));

        // Check failed lookup cache
        if (Cache::has("igdb_search_failed:{$cacheKey}")) {
            Log::debug('IGDBService: Skipping previously failed search', ['title' => $title]);

            return null;
        }

        // Check successful search cache
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::debug('IGDBService: Using cached search result', ['title' => $title]);

            return $cached instanceof Game ? $cached : null;
        }

        $game = $this->searchWithStrategies($title, self::PC_PLATFORM_IDS);

        if ($game !== null) {
            Cache::put($cacheKey, $game, self::GAME_CACHE_TTL);
            Log::info('IGDBService: Found match', ['title' => $title, 'matched' => $game->name]); // @phpstan-ignore property.notFound
        } else {
            Cache::put("igdb_search_failed:{$cacheKey}", true, self::FAILED_LOOKUP_CACHE_TTL);
            Log::debug('IGDBService: No match found', ['title' => $title]);
        }

        return $game;
    }

    /**
     * Search for a console game using an optional platform hint.
     */
    public function searchConsole(string $title, string $platformHint): ?Game
    {
        if (! $this->isConfigured() || $title === '') {
            return null;
        }

        $cacheKey = 'igdb_console_search:'.self::SEARCH_CACHE_VERSION.':'.md5(mb_strtolower($title.'|'.$platformHint));

        if (Cache::has("igdb_console_search_failed:{$cacheKey}")) {
            Log::debug('IGDBService: Skipping previously failed console search', [
                'title' => $title,
                'platform' => $platformHint,
            ]);

            return null;
        }

        $cached = Cache::get($cacheKey);
        if ($cached instanceof Game) {
            Log::debug('IGDBService: Using cached console search result', [
                'title' => $title,
                'platform' => $platformHint,
            ]);

            return $cached;
        }

        $game = $this->searchWithStrategies($title, null, $platformHint);

        if ($game instanceof Game) {
            Cache::put($cacheKey, $game, self::GAME_CACHE_TTL);

            return $game;
        }

        Cache::put("igdb_console_search_failed:{$cacheKey}", true, self::FAILED_LOOKUP_CACHE_TTL);

        return null;
    }

    /**
     * Get complete game details from a Game object.
     *
     * @return array<string, mixed>
     *                              title: string,
     *                              asin: string,
     *                              review: string,
     *                              coverurl: string,
     *                              releasedate: string,
     *                              esrb: ?string,
     *                              url: string,
     *                              backdropurl: string,
     *                              trailer: string,
     *                              publisher: string,
     *                              developer: string,
     *                              genres: array,
     *                              }|false
     * @return array<string, mixed>
     */
    public function getGameDetails(Game $game): array|false
    {
        if (empty($game->name)) {
            return false;
        }

        $genreName = '';

        return $this->buildGameData($game, $genreName);
    }

    /**
     * Search IGDB using multiple strategies for better match rates.
     */
    protected function searchWithStrategies(string $title, ?array $platformIds = null, ?string $platformHint = null): ?Game
    {
        // Strategy 1: Exact name search with PC platform filter
        $game = $this->searchExact($title, $platformIds, $platformHint);
        if ($game !== null) {
            Log::debug('IGDBService: Exact match found', ['title' => $title, 'matched' => $game->name]); // @phpstan-ignore property.notFound

            return $game;
        }

        // Strategy 2: Fuzzy search using IGDB's search endpoint
        $game = $this->searchFuzzy($title, $platformIds, $platformHint);
        if ($game !== null) {
            Log::debug('IGDBService: Fuzzy match found', ['title' => $title, 'matched' => $game->name]); // @phpstan-ignore property.notFound

            return $game;
        }

        // Strategy 3: Search without special characters
        $cleanTitle = preg_replace('/[^a-zA-Z0-9\s]/', '', $title);
        if ($cleanTitle !== $title && $cleanTitle !== '') {
            $game = $this->searchFuzzy($cleanTitle, $platformIds, $platformHint);
            if ($game !== null) {
                Log::debug('IGDBService: Clean title match found', ['title' => $title, 'matched' => $game->name]); // @phpstan-ignore property.notFound

                return $game;
            }
        }

        // Strategy 4: Try with common subtitle patterns removed
        $baseTitle = $this->extractBaseTitle($title);
        if ($baseTitle !== $title && $baseTitle !== $cleanTitle && $baseTitle !== '') {
            $game = $this->searchFuzzy($baseTitle, $platformIds, $platformHint);
            if ($game !== null) {
                Log::debug('IGDBService: Base title match found', ['title' => $title, 'matched' => $game->name]); // @phpstan-ignore property.notFound

                return $game;
            }
        }

        return null;
    }

    /**
     * Exact name search on IGDB with PC platform filter.
     */
    protected function searchExact(string $title, ?array $platformIds = null, ?string $platformHint = null): ?Game
    {
        try {
            $results = RateLimiter::attempt(
                self::RATE_LIMIT_KEY,
                self::REQUESTS_PER_MINUTE,
                function () use ($platformIds, $title) {
                    $query = Game::where('name', $title)
                        ->with($this->getGameRelations())
                        ->orderByDesc('aggregated_rating_count')
                        ->limit(10);

                    if ($platformIds !== null) {
                        $query->whereIn('platforms', $platformIds);
                    }

                    return $query->get();
                },
                self::DECAY_SECONDS
            );

            if ($results === true || empty($results)) {
                return null;
            }

            return $this->findBestMatch($results, $title, $platformHint);
        } catch (\Exception $e) {
            Log::warning('IGDBService: Exact search error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Fuzzy search on IGDB using the search endpoint.
     */
    protected function searchFuzzy(string $title, ?array $platformIds = null, ?string $platformHint = null): ?Game
    {
        try {
            $results = RateLimiter::attempt(
                self::RATE_LIMIT_KEY,
                self::REQUESTS_PER_MINUTE,
                function () use ($platformIds, $title) {
                    $query = Game::search($title)
                        ->where('game_type', 0) // Main game only (not DLC, expansion, etc.)
                        ->with($this->getGameRelations())
                        ->orderByDesc('aggregated_rating_count')
                        ->limit(10);

                    if ($platformIds !== null) {
                        $query->whereIn('platforms', $platformIds);
                    }

                    return $query->get();
                },
                self::DECAY_SECONDS
            );

            if ($results === true || empty($results)) {
                return null;
            }

            return $this->findBestMatch($results, $title, $platformHint);
        } catch (\Exception $e) {
            Log::warning('IGDBService: Fuzzy search error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The game with this IGDB id, asked afresh: a cached answer is never returned. Null when IGDB
     * has no such game.
     *
     * @throws IgdbHttpException when IGDB cannot be asked, with status 429 when the rate limit is spent
     */
    public function findGame(int $igdbId): ?Game
    {
        $result = RateLimiter::attempt(
            self::RATE_LIMIT_KEY,
            self::REQUESTS_PER_MINUTE,
            fn (): mixed => Game::query()->where('id', $igdbId)->with($this->getGameRelations())->fresh()->first(),
            self::DECAY_SECONDS
        );

        if ($result === false) {
            throw new IgdbHttpException('IGDB rate limit reached', 429);
        }

        return $result instanceof Game ? $result : null;
    }

    /**
     * Get relations to load with IGDB queries.
     *
     * @return array<string, mixed>
     */
    protected function getGameRelations(): array
    {
        return [
            'cover' => ['url', 'image_id'],
            'screenshots' => ['url', 'image_id'],
            'artworks' => ['url', 'image_id'],
            'videos' => ['video_id', 'name'],
            'involved_companies' => ['company.name', 'publisher', 'developer'],
            'genres' => ['name'],
            'themes' => ['name'],
            'game_modes' => ['name'],
            'player_perspectives' => ['name'],
            'age_ratings' => ['organization.name', 'rating_category.rating'],
            'websites' => ['url', 'type'],
            'platforms' => ['name', 'abbreviation'],
            'release_dates' => ['date', 'platform', 'human'],
        ];
    }

    /**
     * Find the best matching game from results.
     */
    protected function findBestMatch(mixed $results, string $title, ?string $platformHint = null): ?Game
    {
        $bestMatch = null;
        $bestScore = 0;
        $normalizedQuery = $this->normalizeForMatch($title);

        foreach ($results as $game) {
            $normalizedName = $this->normalizeForMatch($game->name);
            $score = $this->computeSimilarity($normalizedQuery, $normalizedName);

            // Boost score for games with more ratings (more popular/verified)
            if (isset($game->aggregated_rating_count) && $game->aggregated_rating_count > 10) {
                $score += min(5, $game->aggregated_rating_count / 100);
            }

            // Boost score for games with covers (more complete data)
            if (isset($game->cover)) {
                $score += 2;
            }

            if ($platformHint !== null && $platformHint !== '') {
                $platformScore = $this->getPlatformMatchScore($game, $platformHint);
                $score += $platformScore > 0 ? $platformScore : -10;
            }

            // Check alternative names if available
            if (isset($game->alternative_names)) {
                foreach ($game->alternative_names as $altName) {
                    $altScore = $this->computeSimilarity($normalizedQuery, $this->normalizeForMatch($altName['name'] ?? ''));
                    if ($altScore > $score) {
                        $score = $altScore;
                    }
                }
            }

            if ($score >= self::MATCH_THRESHOLD && $score > $bestScore) {
                $bestMatch = $game;
                $bestScore = $score;
            }
        }

        if ($bestMatch !== null) {
            Log::debug('IGDBService: Best match selected', [
                'query' => $title,
                'matched' => $bestMatch->name,
                'score' => $bestScore,
            ]);
        }

        return $bestMatch;
    }

    /**
     * Build console-specific game data from an IGDB Game model.
     *
     * @return array<string, mixed>
     */
    public function buildConsoleData(Game $game, string $platformHint): array
    {
        $this->companyNames = [];
        $genres = $this->extractGenres($game);
        $developers = $this->extractCompanies($game, 'developer');
        $publishers = $this->extractCompanies($game, 'publisher');
        $publisherNames = array_values(array_unique(array_column($publishers, 'name')));
        $platform = $this->resolvePlatform($game, $platformHint);

        return [
            'title' => $game->name,
            'asin' => (string) $game->id,
            'review' => (string) ($game->summary ?? ''),
            'coverurl' => $this->getImageUrl($game->cover ?? null, 'cover_big'),
            'releasedate' => $this->getPlatformReleaseDate($game, $platform['id']),
            'esrb' => $this->getAgeRating($game),
            'url' => $game->url ?? '',
            'publisher' => ! empty($publisherNames) ? implode(',', $publisherNames) : 'Unknown',
            'platform' => $platform['name'],
            'consolegenre' => ! empty($genres) ? implode(',', $genres) : 'Unknown',
            'consolegenres' => ! empty($genres) ? array_values($genres) : ['Unknown'],
            'salesrank' => '',
            'storyline' => $this->storyline($game),
            'critic_score' => $this->score($game->aggregated_rating ?? null),
            'user_score' => $this->score($game->rating ?? null),
            'website' => $this->officialWebsite($game),
            'developers' => $developers,
            'publishers' => $publishers,
            'game_modes' => $this->namedEntries($game->game_modes ?? null),
            'player_perspectives' => $this->namedEntries($game->player_perspectives ?? null),
        ];
    }

    /**
     * Build game data array from IGDB Game model.
     *
     * @return array<string, mixed>
     */
    public function buildGameData(Game $game, string &$genreName): array
    {
        $this->companyNames = [];

        // Extract publishers and developers
        $publishers = [];
        $developers = [];
        if (! empty($game->involved_companies)) {
            $involvedCompanies = $game->involved_companies;
            if ($involvedCompanies instanceof Collection) {
                $involvedCompanies = $involvedCompanies->toArray();
            }
            foreach ($involvedCompanies as $company) {
                $isPublisher = is_array($company) ? ($company['publisher'] ?? false) : ($company->publisher ?? false);
                $isDeveloper = is_array($company) ? ($company['developer'] ?? false) : ($company->developer ?? false);

                if ($isPublisher === true && ($companyData = $this->involvedCompany($company)) !== null) {
                    $publishers[] = $companyData[1];
                }
                if ($isDeveloper === true && ($companyData = $this->involvedCompany($company)) !== null) {
                    $developers[] = $companyData[1];
                }
            }
        }

        // Extract genres
        $genres = $this->extractGenres($game);
        $genreName = $this->matchGenre(implode(',', array_filter($genres)));

        // Get cover and backdrop URLs
        $coverUrl = $this->getImageUrl($game->cover ?? null, 'cover_big');
        $backdropUrl = $this->getBackdropUrl($game);

        // Get trailer URL
        $trailerUrl = $this->getTrailerUrl($game);

        // Get rating
        $esrb = $this->getAgeRating($game);

        // Get release date for PC
        $releaseDate = $this->getReleaseDate($game);

        // Get game URL
        $gameUrl = $game->url ?? ('https://www.igdb.com/games/'.($game->slug ?? $game->id)); // @phpstan-ignore property.notFound

        // Build review text
        $review = $this->buildReview($game, $developers); // @phpstan-ignore argument.type

        Log::info('IGDBService: Game data built', [
            'title' => $game->name, // @phpstan-ignore property.notFound
            'id' => $game->id, // @phpstan-ignore property.notFound
            'has_cover' => ! empty($coverUrl),
            'has_backdrop' => ! empty($backdropUrl),
            'genres' => $genres,
        ]);

        return [
            'title' => $game->name, // @phpstan-ignore property.notFound
            'asin' => 'igdb-'.$game->id, // @phpstan-ignore property.notFound
            'review' => $review,
            'coverurl' => $coverUrl,
            'releasedate' => $releaseDate,
            'esrb' => $esrb,
            'url' => $gameUrl,
            'backdropurl' => $backdropUrl,
            'trailer' => $trailerUrl,
            'publisher' => ! empty($publishers) ? implode(', ', array_slice($publishers, 0, 3)) : 'Unknown',
            'developer' => ! empty($developers) ? implode(', ', array_slice($developers, 0, 3)) : '',
            'genres' => $genres,
        ];
    }

    /**
     * Extract genres from game data.
     *
     * @return array<string, mixed>
     */
    protected function extractGenres(Game $game): array
    {
        $genres = [];
        if (! empty($game->genres)) {
            $gameGenres = $game->genres;
            if ($gameGenres instanceof Collection) {
                $gameGenres = $gameGenres->toArray();
            }
            foreach ($gameGenres as $genre) {
                $genres[] = is_array($genre) ? ($genre['name'] ?? '') : ($genre->name ?? '');
            }
        }

        // Fall back to themes if no genres
        if (empty($genres) && ! empty($game->themes)) {
            $gameThemes = $game->themes;
            if ($gameThemes instanceof Collection) {
                $gameThemes = $gameThemes->toArray();
            }
            foreach ($gameThemes as $theme) {
                $genres[] = is_array($theme) ? ($theme['name'] ?? '') : ($theme->name ?? '');
            }
        }

        return array_filter($genres);
    }

    /**
     * The involved companies flagged `developer` or `publisher`, in IGDB's order, each company
     * once. A company with no name is left out.
     *
     * @return list<array{igdb_id: int, name: string}>
     */
    protected function extractCompanies(Game $game, string $flag): array
    {
        $companies = [];
        if (empty($game->involved_companies)) {
            return $companies;
        }

        $involvedCompanies = $game->involved_companies;
        if ($involvedCompanies instanceof Collection) {
            $involvedCompanies = $involvedCompanies->toArray();
        }

        foreach ($involvedCompanies as $company) {
            $isMatch = is_array($company) ? ($company[$flag] ?? false) : ($company->{$flag} ?? false);
            if ($isMatch !== true) {
                continue;
            }

            $companyData = $this->involvedCompany($company);
            if ($companyData === null || $companyData[1] === '' || isset($companies[$companyData[0]])) {
                continue;
            }

            $companies[$companyData[0]] = ['igdb_id' => $companyData[0], 'name' => $companyData[1]];
        }

        return array_values($companies);
    }

    /**
     * An involved company entry's company as [IGDB id, name]: the expanded `company` object, else,
     * for a bare id (an answer cached before the name was requested), the `companies` endpoint,
     * asked once per company for the rest of the call. Null when it has no id or IGDB has none.
     *
     * @return array{int, string}|null
     */
    private function involvedCompany(mixed $entry): ?array
    {
        $company = $this->nodeValue($entry, 'company');

        if (is_array($company) || $company instanceof \ArrayAccess) {
            $id = $this->nodeValue($company, 'id');
            if (! is_numeric($id) || (int) $id <= 0) {
                return null;
            }

            return [(int) $id, trim((string) ($this->nodeValue($company, 'name') ?? ''))];
        }

        if (! is_numeric($company) || (int) $company <= 0) {
            return null;
        }

        $id = (int) $company;
        if (! array_key_exists($id, $this->companyNames)) {
            $found = Company::find($id);
            $this->companyNames[$id] = $found !== null ? trim((string) ($found->name ?? '')) : null;
        }

        return $this->companyNames[$id] !== null ? [$id, $this->companyNames[$id]] : null;
    }

    private function storyline(Game $game): ?string
    {
        $storyline = $game->storyline ?? null;
        $storyline = is_string($storyline) ? trim($storyline) : '';

        return $storyline !== '' ? $storyline : null;
    }

    private function score(mixed $rating): ?int
    {
        return is_numeric($rating) ? (int) round((float) $rating) : null;
    }

    /**
     * The URL of the first website of type 1 (Official Website); null when there is none or it
     * does not fit consoleinfo.website.
     */
    private function officialWebsite(Game $game): ?string
    {
        foreach ((array) ($game->websites ?? []) as $website) {
            if ((int) $this->nodeValue($website, 'type') !== self::OFFICIAL_WEBSITE_TYPE) {
                continue;
            }

            $url = trim((string) ($this->nodeValue($website, 'url') ?? ''));

            return $url !== '' && mb_strlen($url) <= self::WEBSITE_LENGTH ? $url : null;
        }

        return null;
    }

    /**
     * Named relation entries (game modes, player perspectives) in IGDB's order, each name once,
     * with IGDB's id when it sends one. An entry with a blank name is left out.
     *
     * @return list<array{igdb_id: int|null, name: string}>
     */
    private function namedEntries(mixed $entries): array
    {
        if ($entries instanceof Collection) {
            $entries = $entries->all();
        }

        $named = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            $name = $this->nodeValue($entry, 'name');
            $name = is_string($name) ? trim($name) : '';
            if ($name === '' || isset($named[$name])) {
                continue;
            }

            $id = $this->nodeValue($entry, 'id');
            $named[$name] = ['igdb_id' => is_numeric($id) ? (int) $id : null, 'name' => $name];
        }

        return array_values($named);
    }

    /**
     * Resolve the game's platform matching the hint (else its first named platform) to its IGDB id and name.
     *
     * @return array{id: int|null, name: string}
     */
    protected function resolvePlatform(Game $game, string $platformHint): array
    {
        $normalizedHint = $this->normalizePlatformHint($platformHint);
        $fallback = ['id' => null, 'name' => ''];

        foreach ((array) ($game->platforms ?? []) as $platform) {
            $name = is_array($platform) ? (string) ($platform['name'] ?? '') : (string) ($platform->name ?? '');
            $abbreviation = is_array($platform) ? (string) ($platform['abbreviation'] ?? '') : (string) ($platform->abbreviation ?? '');
            $id = is_array($platform) ? ($platform['id'] ?? null) : ($platform->id ?? null);
            $id = is_numeric($id) ? (int) $id : null;

            if ($fallback['name'] === '' && $name !== '') {
                $fallback = ['id' => $id, 'name' => $name];
            }

            foreach ([$name, $abbreviation] as $candidate) {
                if ($candidate !== '' && $this->normalizePlatformHint($candidate) === $normalizedHint) {
                    return ['id' => $id, 'name' => $name !== '' ? $name : $candidate];
                }
            }
        }

        return $fallback;
    }

    protected function getPlatformMatchScore(Game $game, string $platformHint): int
    {
        $normalizedHint = $this->normalizePlatformHint($platformHint);
        if ($normalizedHint === '') {
            return 0;
        }

        foreach ((array) ($game->platforms ?? []) as $platform) {
            $name = is_array($platform) ? (string) ($platform['name'] ?? '') : (string) ($platform->name ?? '');
            $abbreviation = is_array($platform) ? (string) ($platform['abbreviation'] ?? '') : (string) ($platform->abbreviation ?? '');

            foreach ([$name, $abbreviation] as $candidate) {
                if ($candidate === '') {
                    continue;
                }

                if ($this->normalizePlatformHint($candidate) === $normalizedHint) {
                    return 15;
                }
            }
        }

        return 0;
    }

    protected function normalizePlatformHint(string $platform): string
    {
        $normalized = mb_strtolower(trim($platform));

        return self::PLATFORM_ALIASES[$normalized] ?? $normalized;
    }

    /**
     * Get properly formatted IGDB image URL.
     *
     * @param  array<string, mixed>  $imageData
     * @return array<int<0, max>, mixed>
     */
    public function getImageUrl(array|object|null $imageData, string $size = 'cover_big'): string
    {
        if (empty($imageData)) {
            return '';
        }

        if (is_object($imageData)) {
            $imageId = $imageData->image_id ?? ($imageData->imageId ?? null);
            $url = $imageData->url ?? null;
            $imageData = [
                'image_id' => $imageId,
                'url' => $url,
            ];
        }

        if (! empty($imageData['image_id'])) {
            return 'https://images.igdb.com/igdb/image/upload/t_'.$size.'/'.$imageData['image_id'].'.jpg';
        }

        if (! empty($imageData['url'])) {
            $url = $imageData['url'];
            if (str_starts_with($url, '//')) {
                $url = 'https:'.$url;
            }

            return preg_replace('/t_[a-z0-9_]+/', 't_'.$size, $url);
        }

        return '';
    }

    /**
     * Get backdrop URL from artworks or screenshots.
     */
    protected function getBackdropUrl(Game $game): string
    {
        if (! empty($game->artworks)) {
            $artworks = $game->artworks;
            $firstArtwork = ($artworks instanceof Collection) ? $artworks->first() : ($artworks[0] ?? null);
            $url = $this->getImageUrl($firstArtwork, '1080p');
            if (! empty($url)) {
                return $url;
            }
        }

        if (! empty($game->screenshots)) {
            $screenshots = $game->screenshots;
            $firstScreenshot = ($screenshots instanceof Collection) ? $screenshots->first() : ($screenshots[0] ?? null);

            return $this->getImageUrl($firstScreenshot, '1080p');
        }

        return '';
    }

    /**
     * Get trailer URL from videos.
     */
    protected function getTrailerUrl(Game $game): string
    {
        if (! empty($game->videos)) {
            $videos = $game->videos;
            if ($videos instanceof Collection) {
                $videos = $videos->toArray();
            }
            foreach ($videos as $video) {
                $videoId = is_array($video) ? ($video['video_id'] ?? null) : ($video->video_id ?? null);
                if ($videoId) {
                    return 'https://www.youtube.com/watch?v='.$videoId;
                }
            }
        }

        return '';
    }

    /**
     * The game's age rating: the bare ESRB code when IGDB has an ESRB rating, wherever
     * it is listed, else "PEGI " plus the PEGI rating, else null. Ratings from the
     * other organisations are ignored.
     */
    protected function getAgeRating(Game $game): ?string
    {
        $ageRatings = $game->age_ratings ?? [];
        if ($ageRatings instanceof Collection) {
            $ageRatings = $ageRatings->all();
        }

        if (! is_array($ageRatings)) {
            return null;
        }

        $pegi = null;

        foreach ($ageRatings as $ageRating) {
            $organization = $this->nodeValue($this->nodeValue($ageRating, 'organization'), 'name');
            $rating = $this->nodeValue($this->nodeValue($ageRating, 'rating_category'), 'rating');

            if (! is_string($rating) && ! is_int($rating)) {
                continue;
            }

            $rating = trim((string) $rating);
            if ($rating === '') {
                continue;
            }

            if ($organization === 'ESRB') {
                return $rating;
            }

            if ($organization === 'PEGI' && $pegi === null) {
                $pegi = 'PEGI '.$rating;
            }
        }

        return $pegi;
    }

    /**
     * Read a field from an IGDB node, which the client hydrates as a DataNode or array.
     */
    private function nodeValue(mixed $node, string $key): mixed
    {
        if (is_array($node) || $node instanceof \ArrayAccess) {
            return $node[$key] ?? null;
        }

        return null;
    }

    /**
     * Get PC release date from IGDB game data: the PC entry, else the first entry, else the first
     * release date, else none ('').
     */
    protected function getReleaseDate(Game $game): string
    {
        if (! empty($game->release_dates)) {
            foreach ($game->release_dates as $release) {
                if (isset($release['platform']) && $release['platform'] === 6 && isset($release['date'])) {
                    return Carbon::createFromTimestamp($release['date'])->format('Y-m-d');
                }
            }
            if (isset($game->release_dates[0]['date'])) {
                return Carbon::createFromTimestamp($game->release_dates[0]['date'])->format('Y-m-d');
            }
        }

        return $this->getFirstReleaseDate($game);
    }

    /**
     * Get a console game's release date: the earliest dated entry of the given platform (IGDB keeps
     * one entry per platform per region, in no date order), else the first release date, else
     * none (''). Release date entries of other platforms are never used.
     */
    protected function getPlatformReleaseDate(Game $game, ?int $platformId): string
    {
        if ($platformId !== null) {
            $earliest = null;
            $releases = $game->release_dates ?? [];

            foreach (is_iterable($releases) ? $releases : [] as $release) {
                $platform = $this->nodeValue($release, 'platform');
                $date = $this->nodeValue($release, 'date');

                if (is_numeric($platform) && (int) $platform === $platformId && is_numeric($date)) {
                    $earliest = $earliest === null ? (int) $date : min($earliest, (int) $date);
                }
            }

            if ($earliest !== null) {
                return Carbon::createFromTimestamp($earliest)->format('Y-m-d');
            }
        }

        return $this->getFirstReleaseDate($game);
    }

    /**
     * Get the game's first release date, or none ('') when IGDB has no date.
     */
    protected function getFirstReleaseDate(Game $game): string
    {
        if (isset($game->first_release_date)) {
            if ($game->first_release_date instanceof Carbon) {
                return $game->first_release_date->format('Y-m-d');
            }
            if (is_numeric($game->first_release_date)) {
                return Carbon::createFromTimestamp($game->first_release_date)->format('Y-m-d');
            }
        }

        return '';
    }

    /**
     * Build review/summary text.
     *
     * @param  array<string, mixed>  $developers
     */
    protected function buildReview(Game $game, array $developers): string
    {
        $review = $game->summary ?? '';
        if (empty($review) && isset($game->storyline)) {
            $review = $game->storyline;
        }

        $additionalInfo = [];
        if (! empty($developers)) {
            $additionalInfo[] = 'Developer: '.implode(', ', array_slice($developers, 0, 3));
        }
        if (! empty($game->game_modes)) {
            $gameModes = $game->game_modes;
            if ($gameModes instanceof Collection) {
                $modes = $gameModes->map(fn ($m) => is_array($m) ? ($m['name'] ?? '') : ($m->name ?? ''))->toArray();
            } else {
                $modes = array_map(fn ($m) => $m['name'] ?? '', $gameModes);
            }
            $additionalInfo[] = 'Modes: '.implode(', ', array_filter($modes));
        }
        if (! empty($additionalInfo) && ! empty($review)) {
            $review .= "\n\n".implode("\n", $additionalInfo);
        }

        return $review;
    }

    /**
     * Extract base title by removing common subtitle patterns.
     */
    protected function extractBaseTitle(string $title): string
    {
        $patterns = [
            '/\s*[-:]\s+.*$/',
            '/\s+(?:Episode|Chapter|Part)\s+\d+.*/i',
            '/\s+(?:Vol(?:ume)?\.?\s*\d+).*/i',
            '/\s+\d+$/',
        ];

        $baseTitle = $title;
        foreach ($patterns as $pattern) {
            $baseTitle = preg_replace($pattern, '', $baseTitle);
        }

        return trim($baseTitle);
    }

    /**
     * Normalize title for matching.
     */
    protected function normalizeForMatch(string $title): string
    {
        $t = mb_strtolower($title);
        $t = (string) preg_replace('/\b(game of the year|goty|definitive edition|deluxe edition|ultimate edition|complete edition|remastered|hd remaster|directors? cut|anniversary edition|update|patch|hotfix|incl(?:uding)? dlcs?|dlcs?|repack|rip|iso|crack(?:fix)?|beta|alpha)\b/i', ' ', $t);
        $t = (string) preg_replace('/\b(pc|gog|steam|x64|x86|win64|win32|mult[iy]?\d*|eng|english|fr|french|de|german|es|spanish|it|italian|pt|ptbr|portuguese|ru|russian|pl|polish|tr|turkish|nl|dutch|se|swedish|no|norwegian|da|danish|fi|finnish|jp|japanese|cn|chs|cht|ko|korean)\b/i', ' ', $t);
        $t = (string) preg_replace('/[^a-z0-9]+/i', ' ', $t);
        $t = trim(preg_replace('/\s{2,}/', ' ', $t));

        return $t;
    }

    /**
     * Compute similarity between two strings.
     */
    protected function computeSimilarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 100.0;
        }
        $percent = 0.0;
        similar_text($a, $b, $percent);

        $levScore = 0.0;
        $len = max(strlen($a), strlen($b));
        if ($len > 0) {
            $dist = levenshtein($a, $b);
            if ($dist >= 0) {
                $levScore = (1 - ($dist / $len)) * 100.0;
            }
        }

        return max($percent, $levScore);
    }

    /**
     * Match genre string to known genres.
     */
    public function matchGenre(string $genre): string
    {
        $genreName = '';
        $a = str_replace('-', ' ', $genre);
        $tmpGenre = explode(',', $a);
        foreach ($tmpGenre as $tg) {
            $genreMatch = $this->isKnownGenre(ucwords(trim($tg)));
            if ($genreMatch !== false) {
                $genreName = (string) $genreMatch;
                break;
            }
            if (empty($genreName) && ! empty($tmpGenre[0])) {
                $genreName = trim($tmpGenre[0]);
            }
        }

        return $genreName;
    }

    /**
     * Check if genre is in known genres list.
     */
    public function isKnownGenre(string $gameGenre): bool|string
    {
        $knownGenres = [
            'Action', 'Adventure', 'Arcade', 'Board Games', 'Cards', 'Casino',
            'Flying', 'Puzzle', 'Racing', 'Rhythm', 'Role-Playing', 'RPG',
            'Simulation', 'Sports', 'Strategy', 'Trivia', 'Shooter', 'FPS',
            'Horror', 'Survival', 'Sandbox', 'Open World', 'Platformer', 'Fighting',
            'Stealth', 'MMO', 'MMORPG', 'Battle Royale', 'Roguelike', 'Roguelite',
            'Metroidvania', 'Visual Novel', 'Point & Click', 'Management',
            'City Builder', 'Tower Defense', 'Turn-Based', 'Real-Time',
            'Educational', 'Music', 'Party', 'Indie', 'Hack and Slash',
            'Souls-like', 'JRPG', 'ARPG', 'Tactical',
        ];

        return in_array($gameGenre, $knownGenres, true) ? $gameGenre : false;
    }

    /**
     * Clear lookup caches.
     */
    public function clearCache(): void
    {
        Log::info('IGDBService: Cache clear requested');
    }
}
