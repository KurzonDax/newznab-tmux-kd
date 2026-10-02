<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ImageAssetProfile;
use App\Enums\SecondarySearchIndex;
use App\Facades\Search;
use App\Models\Category;
use App\Models\ConsoleInfo;
use App\Models\Release;
use App\Models\Settings;
use App\Services\IGDB\Exceptions\IgdbHttpException;
use App\Services\MetadataProcessing\ConsoleGameDetails;
use App\Services\MetadataProcessing\ConsoleGenres;
use App\Services\MetadataProcessing\ConsoleProcessingCandidateQuery;
use App\Services\Releases\CoverBrowseScope;
use App\Services\Releases\ReleaseBrowseService;
use App\Support\CoverBrowseResults;
use App\Support\LookupThrottle;
use App\Support\MetadataSearchLookup;
use App\Support\SecondaryIndexDocuments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ConsoleService - Console/Game processing service.
 *
 * Features:
 * - Console game info retrieval and management
 * - Release processing with IGDB lookup
 * - Title parsing and matching
 * - Browse/search functionality
 * - Cover image handling
 */
class ConsoleService
{
    public const int CONS_UPROC = 0; // Release has not been processed.

    public const int CONS_NTFND = -2;

    /** A stored game is asked about again on its next release once this many hours have passed. */
    private const int REFRESH_AFTER_HOURS = 24;

    public bool $echoOutput;

    public int $gameQty;

    public int $lookupThrottleMs;

    public string $imgSavePath;

    /**
     * @var array<string, mixed>
     */
    public array $failCache;

    protected IGDBService $igdbService;

    protected ReleaseImageService $imageService;

    protected ConsoleGenres $consoleGenres;

    protected ConsoleGameDetails $consoleGameDetails;

    public function __construct(?ReleaseImageService $imageService = null, ?IGDBService $igdbService = null, ?ConsoleGenres $consoleGenres = null, ?ConsoleGameDetails $consoleGameDetails = null)
    {
        $this->echoOutput = config('nntmux.echocli');
        $this->imageService = $imageService ?? new ReleaseImageService;
        $this->igdbService = $igdbService ?? new IGDBService;
        $this->consoleGenres = $consoleGenres ?? new ConsoleGenres;
        $this->consoleGameDetails = $consoleGameDetails ?? new ConsoleGameDetails;

        $this->gameQty = (int) Settings::settingValueOr('maxgamesprocessed', 150);
        $this->lookupThrottleMs = (int) Settings::settingValueOr('amazonsleep', 1000);
        $this->imgSavePath = config('nntmux_settings.covers_path').'/console/';
        $this->failCache = [];
    }

    // ========================================
    // Console Info Retrieval Methods
    // ========================================

    /**
     * Get console info by ID.
     */
    public function getConsoleInfo(int $id): ?Model
    {
        return ConsoleInfo::query()
            ->where('consoleinfo.id', $id)
            ->select('consoleinfo.*', 'genres.title as genres')
            ->leftJoin('genres', 'genres.id', '=', 'consoleinfo.genres_id')
            ->first();
    }

    /**
     * Get console info by name using full-text search.
     */
    public function getConsoleInfoByName(string $title, string $platform): Model|false
    {
        $searchWords = '';

        $title = preg_replace('/( - | -|\(.+\)|\(|\))/', ' ', $title);
        $title = preg_replace('/[^\w ]+/', '', $title);
        $title = trim(trim(preg_replace('/\s\s+/i', ' ', $title)));

        foreach (explode(' ', $title) as $word) {
            $word = trim(rtrim(trim($word), '-'));
            if ($word !== '' && $word !== '-') {
                $word = '+'.$word;
                $searchWords .= sprintf('%s ', $word);
            }
        }
        $searchWords = trim($searchWords.'+'.$platform);

        if (Search::isAvailable()) {
            $q = MetadataSearchLookup::normalizeBooleanSearchWords($searchWords);
            if ($q !== '') {
                $hits = Search::searchSecondary(SecondarySearchIndex::Console, $q, 25);
                $consoleIds = array_values(array_map('intval', $hits['id'] ?? []));
                if ($consoleIds !== []) {
                    $rowsById = ConsoleInfo::query()
                        ->whereIn('id', $consoleIds)
                        ->get()
                        ->keyBy('id');

                    foreach ($consoleIds as $consoleId) {
                        if ($rowsById->has($consoleId)) {
                            /** @var ConsoleInfo $console */
                            $console = $rowsById->get($consoleId);

                            return $console;
                        }
                    }
                }
            }

            return false;
        }

        $row = ConsoleInfo::query()
            ->whereRaw('MATCH (title, platform) AGAINST (? IN BOOLEAN MODE)', [$searchWords])
            ->first();

        return $row ?? false;
    }

    // ========================================
    // Browse/Range Methods
    // ========================================

    /**
     * Get console games range with pagination.
     *
     * @param  array<int|string, mixed>  $cat  Category IDs (list or associative)
     * @param  array<string, mixed>  $excludedCats
     *
     * @throws \Exception
     */
    public function getConsoleRange(int $page, array $cat, int $start, int $num, string $orderBy, array $excludedCats = [], ?CoverBrowseScope $scope = null): mixed
    {
        $page = max(1, $page);
        $start = max(0, $start);

        $useIndexForTitlePlatform = $scope === null && Search::isAvailable()
            && (! empty($_REQUEST['title']) || ! empty($_REQUEST['platform']));
        $consoleIdsFromSearch = null;
        if ($useIndexForTitlePlatform) {
            $q = trim(
                stripslashes((string) ($_REQUEST['title'] ?? '')).' '
                .stripslashes((string) ($_REQUEST['platform'] ?? ''))
            );
            if ($q === '') {
                $consoleIdsFromSearch = [];
            } else {
                $consoleIdsFromSearch = Search::searchSecondary(SecondarySearchIndex::Console, $q, 5000)['id'];
            }
            if ($consoleIdsFromSearch === []) {
                return new CoverBrowseResults;
            }
        }

        $browseBy = $scope === null ? $this->getBrowseBy($useIndexForTitlePlatform) : '';
        $consoleInClause = '';
        if (is_array($consoleIdsFromSearch) && $consoleIdsFromSearch !== []) {
            $consoleInClause = ' AND con.id IN ('.implode(',', array_map('intval', $consoleIdsFromSearch)).')';
        }
        $catsrch = '';
        if (\count($cat) > 0 && (int) $cat[0] !== -1) {
            $catsrch = Category::getCategorySearch($cat);
        }
        $exccatlist = '';
        if (\count($excludedCats) > 0) {
            $exccatlist = ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')';
        }
        $order = $scope?->order('con') ?? $this->getConsoleOrder($orderBy);
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));
        $showPasswords = app(ReleaseBrowseService::class)->showPasswords();

        $baseWhere = "con.title != '' "
            ."AND r.passwordstatus {$showPasswords} "
            .$browseBy.' '
            .$consoleInClause.' '
            .$catsrch.' '
            .$exccatlist.($scope->sql ?? '');

        $cacheKey = md5('console_range_'.$baseWhere.($scope->cacheKey ?? '').$order[0].$order[1].$start.$num.$page);

        $cacheable = $scope->cacheable ?? true;
        $cached = $cacheable ? Cache::get($cacheKey) : null;
        if ($cached !== null) {
            app(ReleaseBrowseService::class)->loadCoverReleaseData($cached);

            return $cached;
        }

        // Step 1: Count total distinct consoles matching filters
        $countSql = 'SELECT COUNT(DISTINCT con.id) AS total '
            .'FROM consoleinfo con '
            .'INNER JOIN releases r ON con.id = r.consoleinfo_id '
            .'WHERE '.$baseWhere;

        $totalResult = DB::select($countSql, $scope->bindings ?? []);
        $totalCount = $totalResult[0]->total ?? 0;

        if ($totalCount === 0) {
            return new CoverBrowseResults([], (int) $totalCount);
        }

        // Step 2: Get paginated console entity list with only needed columns
        $consoleSql = 'SELECT con.id, con.title, con.cover, con.publisher, con.releasedate, con.review, con.url, con.platform, con.esrb, '
            .'con.genres_id, genres.title AS genre, '
            .'MAX(r.postdate) AS latest_postdate, '
            .'COUNT(r.id) AS total_releases '
            .'FROM consoleinfo con '
            .'INNER JOIN releases r ON con.id = r.consoleinfo_id '
            .'LEFT JOIN genres ON con.genres_id = genres.id '
            .'WHERE '.$baseWhere.' '
            .'GROUP BY con.id, con.title, con.cover, con.publisher, con.releasedate, con.review, con.url, con.platform, con.esrb, con.genres_id, genres.title '
            ."ORDER BY {$order[0]} {$order[1]}, con.id ASC "
            ."LIMIT {$num} OFFSET {$start}";

        $consoles = ConsoleInfo::fromQuery($consoleSql, $scope->bindings ?? []);

        if ($consoles->isEmpty()) {
            return new CoverBrowseResults([], (int) $totalCount);
        }

        // Build list of console IDs for release query
        $consoleIds = $consoles->pluck('id')->toArray();
        $inConsoleIds = implode(',', array_map('intval', $consoleIds));

        // Step 3: Get top 2 releases per console using ROW_NUMBER()
        $releasesSql = 'SELECT ranked.* FROM ( '
            .'SELECT r.*, g.name AS group_name, rn.releases_id AS nfoid, df.failed AS failed_count, '
            .'ROW_NUMBER() OVER (PARTITION BY r.consoleinfo_id ORDER BY r.postdate DESC) AS rn '
            .'FROM releases r '
            .'LEFT JOIN usenet_groups g ON g.id = r.groups_id '
            .'LEFT JOIN release_nfos rn ON rn.releases_id = r.id '
            .'LEFT JOIN dnzb_failures df ON df.release_id = r.id '
            ."WHERE r.consoleinfo_id IN ({$inConsoleIds}) "
            ."AND r.passwordstatus {$showPasswords} "
            .$catsrch.' '
            .$exccatlist
            .($scope->sql ?? '')
            .') ranked '
            .'WHERE ranked.rn <= 2 '
            .'ORDER BY ranked.consoleinfo_id, ranked.postdate DESC';

        $releases = DB::select($releasesSql, $scope->bindings ?? []);

        // Group releases by consoleinfo_id for fast lookup
        $releasesByConsole = [];
        foreach ($releases as $release) {
            $releasesByConsole[$release->consoleinfo_id][] = $release;
        }

        // Attach releases to each console entity
        foreach ($consoles as $console) {
            $console->releases = $releasesByConsole[$console->id] ?? []; // @phpstan-ignore assign.propertyReadOnly
        }

        // Set total count on first item
        if ($consoles->isNotEmpty()) {
            $consoles[0]->_totalcount = $totalCount; // @phpstan-ignore property.notFound
        }

        $consoles = new CoverBrowseResults($consoles, (int) $totalCount);
        if ($cacheable) {
            Cache::put($cacheKey, $consoles, $expiresAt);
        }
        app(ReleaseBrowseService::class)->loadCoverReleaseData($consoles);

        return $consoles;
    }

    /**
     * Get console order array.
     *
     * @return array<string, mixed>
     */
    /**
     * @return array{0: string, 1: string}
     */
    public function getConsoleOrder(string $orderBy): array
    {
        $order = ($orderBy === '') ? 'r.postdate' : $orderBy;
        $orderArr = explode('_', $order);

        $orderfield = match ($orderArr[0]) {
            'title' => 'con.title',
            'platform' => 'con.platform',
            'releasedate' => 'con.releasedate',
            'genre' => 'con.genres_id',
            'size' => 'r.size',
            'files' => 'r.totalpart',
            'stats' => 'r.grabs',
            default => 'r.postdate',
        };

        $ordersort = (isset($orderArr[1]) && preg_match('/^asc|desc$/i', $orderArr[1])) ? $orderArr[1] : 'desc';

        return [$orderfield, $ordersort];
    }

    /**
     * Get console ordering options.
     *
     * @return array<int, string>
     */
    public function getConsoleOrdering(): array
    {
        return [
            'title_asc', 'title_desc',
            'posted_asc', 'posted_desc',
            'size_asc', 'size_desc',
            'files_asc', 'files_desc',
            'stats_asc', 'stats_desc',
            'platform_asc', 'platform_desc',
            'releasedate_asc', 'releasedate_desc',
            'genre_asc', 'genre_desc',
        ];
    }

    /**
     * Get browse by options.
     *
     * @return array<string, mixed>
     */
    public function getBrowseByOptions(): array
    {
        return ['platform' => 'platform', 'title' => 'title', 'genre' => 'genres_id'];
    }

    /**
     * Get browse by SQL clause.
     */
    public function getBrowseBy(bool $skipTitlePlatformLike = false): string
    {
        $browseBy = ' ';
        foreach ($this->getBrowseByOptions() as $bbk => $bbv) {
            if ($skipTitlePlatformLike && ($bbk === 'title' || $bbk === 'platform')) {
                continue;
            }
            if (! empty($_REQUEST[$bbk])) {
                $bbs = stripslashes($_REQUEST[$bbk]);
                if (stripos($bbv, 'id') !== false) {
                    $browseBy .= ' AND con.'.$bbv.' = '.(int) $bbs;
                } else {
                    $browseBy .= ' AND con.'.$bbv.' LIKE '.escapeString('%'.$bbs.'%');
                }
            }
        }

        return $browseBy;
    }

    // ========================================
    // Update Methods
    // ========================================

    /**
     * Update console info record. A null summary leaves the stored one as it is.
     */
    public function update(
        int $id,
        string $title,
        ?string $asin,
        ?string $url,
        ?int $salesrank,
        ?string $platform,
        ?string $publisher,
        ?string $releasedate,
        ?string $esrb,
        int $cover,
        ?int $genresId,
        ?string $review = null
    ): void {
        $releasedate = $releasedate !== '' ? $releasedate : null;
        $esrb = $esrb !== '' ? $esrb : null;

        $values = [
            'title' => $title,
            'asin' => $asin,
            'url' => $url,
            'salesrank' => $salesrank,
            'platform' => $platform,
            'publisher' => $publisher,
            'releasedate' => $releasedate,
            'esrb' => $esrb,
            'cover' => $cover,
            'genres_id' => $genresId,
        ];
        if ($review !== null) {
            $values['review'] = substr($review, 0, 3000);
        }

        ConsoleInfo::query()
            ->where('id', $id)
            ->update($values);
    }

    // ========================================
    // IGDB Integration Methods
    // ========================================

    /**
     * Update console info from IGDB.
     *
     *
     * @param  array<string, mixed>  $gameInfo
     *
     * @throws \Exception
     */
    public function updateConsoleInfo(array $gameInfo): int
    {
        $consoleId = self::CONS_NTFND;

        $igdb = $this->fetchIGDBProperties($gameInfo['title'], $gameInfo['platform']);
        if ($igdb !== false) {
            if ($igdb['coverurl'] !== '') {
                $igdb['cover'] = 1;
            } else {
                $igdb['cover'] = 0;
            }

            $consoleId = $this->updateConsoleTable($igdb);

            if ($this->echoOutput && $consoleId !== -2) {
                cli()->header('Added/updated game: ').
                    cli()->alternateOver('   Title:    ').
                    cli()->primary($igdb['title']).
                    cli()->alternateOver('   Platform: ').
                    cli()->primary($igdb['platform']).
                    cli()->alternateOver('   Genre: ').
                    cli()->primary($igdb['consolegenre']);
            }
        }

        return $consoleId;
    }

    /**
     * Fetch IGDB properties for a game.
     *
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    public function fetchIGDBProperties(string $gameInfo, string $gamePlatform): bool|array|\StdClass
    {
        $gamePlatform = $this->replacePlatform($gamePlatform);

        if (! $this->igdbService->isConfigured()) {
            return false;
        }

        try {
            $game = $this->igdbService->searchConsole($gameInfo, $gamePlatform);
            if ($game === null) {
                cli()->notice('IGDB found no valid results');

                return false;
            }

            return $this->igdbService->buildConsoleData($game, $gamePlatform);
        } catch (IgdbHttpException $e) {
            if ($e->getStatusCode() === 429) {
                return false;
            }
        } catch (\Exception $e) {
            cli()->error('Error fetching IGDB properties: '.$e->getMessage());

            return false;
        }

        return false;
    }

    /**
     * Refreshes a stored game from IGDB when a new release of it arrives, unless that was done in
     * the last 24 hours. A game IGDB no longer returns, or whose stored id is not an IGDB id, only
     * gets its stamp. A failure writes nothing and is retried on the game's next release; nothing
     * propagates. Nothing refreshes games in the background.
     *
     * @return bool Whether IGDB was asked.
     */
    public function refreshIfDue(ConsoleInfo $stored): bool
    {
        if (! $this->igdbService->isConfigured()) {
            return false;
        }

        $refreshedAt = $stored->details_refreshed_at;
        if ($refreshedAt !== null && $refreshedAt->gt(now()->subHours(self::REFRESH_AFTER_HOURS))) {
            return false;
        }

        $asin = (string) $stored->asin;
        if (! ctype_digit($asin) || (int) $asin <= 0) {
            // An Amazon ASIN or a typed value: not retried on every release, retried after 24 hours.
            $this->stampDetailsRefreshed((int) $stored->id);

            return false;
        }

        try {
            $game = $this->igdbService->findGame((int) $asin);
            $con = $game !== null ? $this->igdbService->buildConsoleData($game, (string) $stored->platform) : null;
        } catch (\Throwable $e) {
            // A spent rate limit is not an error, as in fetchIGDBProperties(); the next release retries.
            if (! $e instanceof IgdbHttpException || $e->getStatusCode() !== 429) {
                cli()->error('Error refreshing IGDB properties: '.$e->getMessage());
            }

            return true;
        }

        if ($con === null) {
            $this->stampDetailsRefreshed((int) $stored->id);

            return true;
        }

        $con['cover'] = $con['coverurl'] !== '' ? 1 : 0;

        try {
            $this->updateConsoleTable($con);
        } catch (\Throwable $e) {
            cli()->error('Error saving refreshed IGDB properties: '.$e->getMessage());
        }

        return true;
    }

    private function stampDetailsRefreshed(int $consoleId): void
    {
        DB::table('consoleinfo')->where('id', $consoleId)->update(['details_refreshed_at' => now()]);
    }

    // ========================================
    // Release Processing Methods
    // ========================================

    /**
     * Process console releases.
     *
     * @throws \Exception
     */
    public function processConsoleReleases(
        string $groupID = '',
        string $guidChar = '',
        ?int $lookupMode = null,
    ): void {
        $query = ConsoleProcessingCandidateQuery::query($groupID, $guidChar, $lookupMode)
            ->select(['searchname', 'id']);

        $res = $query->limit($this->gameQty)->orderBy('postdate')->get();

        $releaseCount = $res->count();
        if ($res instanceof \Traversable && $releaseCount > 0) {
            if ($this->echoOutput) {
                cli()->header('Processing '.$releaseCount.' console release(s).');
            }

            $throttle = new LookupThrottle($this->lookupThrottleMs);

            foreach ($res as $arr) {
                $throttle->openWindow();
                $usedExternalLookup = false;
                $gameId = self::CONS_NTFND;
                $gameInfo = $this->parseTitle($arr['searchname']);

                if ($gameInfo !== false) {
                    if ($this->echoOutput) {
                        cli()->info('Looking up: '.$gameInfo['title'].' ('.$gameInfo['platform'].')');
                    }

                    // Check for existing console entry.
                    $gameCheck = $this->getConsoleInfoByName($gameInfo['title'], $gameInfo['platform']);

                    if ($gameCheck === false && \in_array($gameInfo['title'].$gameInfo['platform'], $this->failCache, true)) {
                        // Lookup recently failed, no point trying again
                        if ($this->echoOutput) {
                            cli()->info('Cached previous failure. Skipping.');
                        }
                        $gameId = -2;
                    } elseif ($gameCheck === false) {
                        $gameId = $this->updateConsoleInfo($gameInfo);
                        $usedExternalLookup = true;
                        if ($gameId === self::CONS_NTFND) {
                            $this->failCache[] = $gameInfo['title'].$gameInfo['platform'];
                        }
                    } else {
                        if ($this->echoOutput) {
                            cli()->headerOver('Found Local: ').
                                cli()->primary("{$gameInfo['title']} - {$gameInfo['platform']}");
                        }
                        $gameId = $gameCheck['id'] ?? -2;
                        if ($gameId > 0 && $gameCheck instanceof ConsoleInfo && $this->refreshIfDue($gameCheck)) {
                            $usedExternalLookup = true;
                        }
                    }
                } elseif ($this->echoOutput) {
                    echo '.';
                }

                // Update release.
                Release::query()->where('id', $arr['id'])->update(['consoleinfo_id' => $gameId]);

                // Throttle external lookups using the legacy amazonsleep setting.
                if ($usedExternalLookup === true) {
                    $throttle->waitOutWindow();
                }
            }
        } elseif ($this->echoOutput) {
            cli()->header('No console releases to process.');
        }
    }

    // ========================================
    // Title Parsing Methods
    // ========================================

    /**
     * Parse release title for game info.
     *
     * @return array<string, mixed>
     */
    public function parseTitle(string $releaseName): array|false
    {
        $releaseName = preg_replace('/\sMulti\d?\s/i', '', $releaseName);
        $result = [];

        // Get name of the game from name of release.
        if (preg_match('/^(.+((abgx360EFNet|EFNet\sFULL|FULL\sabgxEFNet|abgx\sFULL|abgxbox360EFNet)\s|illuminatenboard\sorg|Place2(hom|us)e.net|united-forums? co uk|\(\d+\)))?(?P<title>.*?)[\.\-_ ](v\.?\d\.\d|PAL|NTSC|EUR|USA|JP|ASIA|JAP|JPN|AUS|MULTI(\.?\d{1,2})?|PATCHED|FULLDVD|DVD5|DVD9|DVDRIP|PROPER|REPACK|RETAIL|DEMO|DISTRIBUTION|REGIONFREE|[\. ]RF[\. ]?|READ\.?NFO|NFOFIX|PSX(2PSP)?|PS[2-4]|PSP|PSVITA|WIIU|WII|X\-?BOX|XBLA|X360|3DS|NDS|N64|NGC)/i', $releaseName, $hits)) {
            $title = $hits['title'];

            // Replace dots, underscores, or brackets with spaces.
            $result['title'] = str_replace(['.', '_', '%20', '[', ']'], ' ', $title);
            $result['title'] = str_replace([' RF ', '.RF.', '-RF-', '_RF_'], ' ', $result['title']);
            // Remove format tags from release title for match
            $result['title'] = trim(preg_replace('/PAL|MULTI(\d)?|NTSC-?J?|\(JAPAN\)/i', '', $result['title']));
            // Remove disc tags from release title for match
            $result['title'] = trim(preg_replace('/Dis[ck] \d.*$/i', '', $result['title']));

            // Needed to add code to handle DLC Properly.
            if (stripos($result['title'], 'dlc') !== false) {
                $result['dlc'] = '1';
                if (stripos($result['title'], 'Rock Band Network') !== false) {
                    $result['title'] = 'Rock Band';
                } elseif (str_contains($result['title'], '-')) {
                    $dlc = explode('-', $result['title']);
                    $result['title'] = $dlc[0];
                } elseif (preg_match('/(.*? .*?) /i', $result['title'], $dlc)) {
                    $result['title'] = $dlc[0];
                }
            }
        } else {
            $title = '';
        }

        // Get the platform of the release.
        if (preg_match('/[\.\-_ ](?P<platform>XBLA|WiiWARE|N64|SNES|NES|PS[2-4]|PS 3|PSX(2PSP)?|PSP|WIIU|WII|XBOX360|XBOXONE|X\-?BOX|X360|3DS|NDS|N?GC)/i', $releaseName, $hits)) {
            $platform = $hits['platform'];

            if (preg_match('/^N?GC$/i', $platform)) {
                $platform = 'NGC';
            }

            if (stripos($platform, 'PSX2PSP') === 0) {
                $platform = 'PSX';
            }

            if (! empty($title) && stripos($platform, 'XBLA') === 0 && stripos($title, 'dlc') !== false) {
                $platform = 'XBOX360';
            }

            $result['platform'] = $platform;
        }

        $result['release'] = $releaseName;
        $result = array_map('trim', $result);

        return (isset($result['title'], $result['platform']) && ! empty($result['title'])) ? $result : false;
    }

    // ========================================
    // Platform Methods
    // ========================================

    /**
     * Normalize a parsed release platform name to the external lookup equivalent.
     */
    public function replacePlatform(string $platform): string
    {
        return match (strtoupper($platform)) {
            'X360', 'XBOX360' => 'Xbox 360',
            'XBOXONE', 'XBOX ONE' => 'Xbox One',
            'DSI', 'NDS' => 'Nintendo DS',
            '3DS' => 'Nintendo 3DS',
            'PS2' => 'PlayStation2',
            'PS3' => 'PlayStation 3',
            'PS4' => 'PlayStation 4',
            'PSP' => 'Sony PSP',
            'PSVITA' => 'PlayStation Vita',
            'PSX', 'PSX2PSP' => 'PlayStation',
            'WIIU' => 'Nintendo Wii U',
            'WII' => 'Nintendo Wii',
            'NGC' => 'GameCube',
            'N64' => 'Nintendo 64',
            'NES' => 'Nintendo NES',
            'SUPER NINTENDO', 'NINTENDO SUPER NES', 'SNES' => 'SNES',
            default => $platform,
        };
    }

    // ========================================
    // Protected Helper Methods
    // ========================================

    /**
     * Update or create console info in the database.
     *
     * @param  array<string, mixed>  $con
     */
    protected function updateConsoleTable(array $con = []): int
    {
        $asin = isset($con['asin']) ? (string) $con['asin'] : null;
        $check = ConsoleInfo::query()->where('asin', $asin)->first();
        // Found or created before any transaction opens, so no transaction holds a new genre
        // another worker cannot see yet.
        $genreIds = $this->consoleGenres->ids($con['consolegenres'] ?? []);
        $linkRows = $this->consoleGameDetails->linkRows($con);
        $details = [
            'storyline' => $con['storyline'] ?? null,
            'critic_score' => $con['critic_score'] ?? null,
            'user_score' => $con['user_score'] ?? null,
            'website' => $con['website'] ?? null,
        ];

        if ($check === null) {
            $consoleId = ConsoleInfo::query()
                ->insertGetId([
                    'title' => $con['title'],
                    'asin' => $asin,
                    'url' => $con['url'],
                    'salesrank' => $con['salesrank'],
                    'platform' => $con['platform'],
                    'publisher' => $con['publisher'],
                    'esrb' => ($con['esrb'] ?? '') !== '' ? $con['esrb'] : null,
                    'releasedate' => $con['releasedate'] !== '' ? $con['releasedate'] : null,
                    'review' => substr($con['review'], 0, 3000),
                    'created_at' => now(),
                    'updated_at' => now(),
                ] + $details);
            // The stamp is written with the genre and link rows, so a failed write leaves it NULL
            // and the game's next release retries.
            $this->consoleGenres->replace($consoleId, $genreIds, fn () => $this->stampDetailsRefreshed($consoleId), $linkRows);

            if ($con['cover'] === 1) {
                $coverSaved = $this->imageService->saveRemoteImage(
                    (string) $consoleId,
                    $con['coverurl'],
                    $this->imgSavePath,
                    ImageAssetProfile::MetadataCover,
                )->success;

                if ($coverSaved) {
                    ConsoleInfo::query()->where('id', $consoleId)->update(['cover' => 1]);
                }
            }

            // insertGetId() fires no model event, so the row is indexed here as ConsoleInfoObserver
            // indexes a row saved through the model.
            $this->indexNewGame($consoleId);
        } else {
            $consoleId = $check['id'];

            // A cover saved over the game's cover file sets the flag; otherwise the stored flag
            // stays, so a cover uploaded on the admin form is kept while IGDB has none.
            $cover = (int) $check['cover'];
            if ($con['cover'] === 1 && $this->imageService->saveRemoteImage(
                (string) $consoleId,
                $con['coverurl'],
                $this->imgSavePath,
                ImageAssetProfile::MetadataCover,
            )->success) {
                $cover = 1;
            }

            $this->consoleGenres->replace($consoleId, $genreIds, function () use ($consoleId, $con, $cover, $genreIds, $details): void {
                $this->update(
                    $consoleId,
                    $con['title'],
                    isset($con['asin']) ? (string) $con['asin'] : null,
                    $con['url'],
                    isset($con['salesrank']) && $con['salesrank'] !== '' ? (int) $con['salesrank'] : null,
                    $con['platform'],
                    $con['publisher'],
                    $con['releasedate'] ?? null,
                    $con['esrb'],
                    $cover,
                    $genreIds[0] ?? null,
                    $con['review'] ?? null
                );
                DB::table('consoleinfo')->where('id', $consoleId)->update($details + ['details_refreshed_at' => now()]);
            }, $linkRows);
        }

        return $consoleId;
    }

    private function indexNewGame(int $consoleId): void
    {
        try {
            $row = DB::table('consoleinfo')->where('id', $consoleId)->first();
            Search::insertSecondary(
                SecondarySearchIndex::Console,
                $consoleId,
                SecondaryIndexDocuments::consoleFromArray((array) $row)
            );
        } catch (\Throwable $e) {
            Log::error('ConsoleService: sync to search index failed', [
                'id' => $consoleId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
