<?php

declare(strict_types=1);

namespace App\Services;

use App\Facades\Search;
use App\Models\Category;
use App\Models\MovieInfo;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\Releases\ReleasePreviewDataLoader;
use App\Support\MovieSearchQuery;
use App\Support\YearRange;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Service class for movie browsing operations (frontend).
 */
class MovieBrowseService
{
    protected string $showPasswords;

    public function __construct(
        private readonly ReleasePreviewDataLoader $previewDataLoader = new ReleasePreviewDataLoader,
    ) {
        $this->showPasswords = app(ReleaseBrowseService::class)->showPasswords();
    }

    /**
     * Add preview data to the release rows attached to a page of movies.
     * Runs on every return path — cached pages store bare rows.
     *
     * @param  iterable<int, object>  $movies
     */
    private function loadPreviewDataForMovies(iterable $movies): void
    {
        $rows = [];
        foreach ($movies as $movie) {
            foreach (($movie->releases ?? []) as $release) {
                $rows[] = $release;
            }
        }

        if ($rows !== []) {
            $this->previewDataLoader->load($rows);
        }
    }

    /**
     * Get movie releases with covers for movie browse page.
     *
     * Uses three separate queries instead of GROUP_CONCAT:
     * 1. COUNT query for total results (replaces SQL_CALC_FOUND_ROWS)
     * 2. Paginated movie list with only needed columns
     * 3. Top 2 releases per movie using a partitioned release rank
     *
     * @param  array<string, mixed>  $cat
     * @param  array<string, mixed>  $excludedCats
     */
    public function getMovieRange(int $page, array $cat, int $start, int $num, string $orderBy, int $maxAge = -1, array $excludedCats = []): mixed
    {
        $page = max(1, $page);
        $start = max(0, $start);

        // Build effective category filter: merge inclusion and exclusion into a single IN clause
        // to avoid redundant IN + NOT IN predicates and help the optimizer
        $catArray = [];
        if (count($cat) > 0 && $cat[0] !== -1) { // @phpstan-ignore offsetAccess.notFound
            $catArray = (array) (Category::getCategorySearch($cat, null, true) ?? []);
        }

        if (! empty($catArray) && ! empty($excludedCats)) {
            $catArray = array_values(array_diff($catArray, array_map('intval', $excludedCats)));
        }

        if (! empty($catArray)) {
            $catFilter = ' AND r.categories_id IN ('.implode(',', $catArray).') ';
            $whereExcluded = '';
        } else {
            $catFilter = '';
            $whereExcluded = count($excludedCats) > 0 ? ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')' : '';
        }

        $order = $this->getMovieOrder($orderBy);
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));

        $whereAge = $maxAge > 0 ? 'AND r.postdate > NOW() - INTERVAL '.$maxAge.' DAY ' : '';

        $movieSearchQuery = MovieSearchQuery::fromInput(request()->all());
        $indexImdbClause = '';
        $textSearchWhere = '';
        if (! $movieSearchQuery->isEmpty()) {
            $foundImdbIds = [];
            if (Search::isAvailable()) {
                $found = Search::searchMoviesByFields($movieSearchQuery->indexTerms(), 8000);
                $foundImdbIds = $found['imdbids'] ?? [];
            }

            if ($foundImdbIds !== []) {
                $quoted = array_map(static fn (string $id): string => escapeString($id), $foundImdbIds);
                $indexImdbClause = ' AND m.imdbid IN ('.implode(',', $quoted).') ';
            } else {
                $textSearchWhere = $this->getTextSearchWhere($movieSearchQuery);
            }
        }

        $browseBy = $this->getBrowseBy();

        $baseWhere = "m.title != '' AND m.imdbid IS NOT NULL AND m.imdbid != '' "
            ."AND r.passwordstatus {$this->showPasswords} "
            .$browseBy.' '
            .$textSearchWhere
            .$indexImdbClause
            .$catFilter
            .$whereAge
            .$whereExcluded;

        // Build a cache key from all the query parameters
        $cacheKey = md5('movie_range_'.$baseWhere.$order[0].$order[1].$start.$num.$page);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            if (is_iterable($cached)) {
                $this->loadPreviewDataForMovies($cached);
            }

            return $cached;
        }

        // Step 1: Count total distinct movies matching filters.
        // Cached separately with a longer TTL (30 min) since the total changes slowly
        // and this query scans all 84K+ movieinfo rows joined to releases (~0.5s).
        $countCacheKey = md5('movie_count_'.$baseWhere);
        $totalCount = Cache::get($countCacheKey);

        if ($totalCount === null) {
            $countSql = 'SELECT COUNT(DISTINCT m.imdbid) AS total '
                .'FROM movieinfo m '
                .'INNER JOIN releases r ON r.imdbid = m.imdbid '
                .'WHERE '.$baseWhere;

            $totalResult = DB::select($countSql);
            $totalCount = $totalResult[0]->total ?? 0;

            Cache::put($countCacheKey, $totalCount, now()->addMinutes(30));
        }

        if ($totalCount === 0) {
            return collect();
        }

        // Step 2: Get paginated movie list using two-phase subquery.
        // Inner query aggregates by just imdbid (small temp table, fast filesort on ~53K
        // narrow rows instead of 53K wide rows with TEXT columns like plot/genre/actors).
        // Outer query joins back to movieinfo for full details on only the top N movies.
        $isAggregateOrder = ($order[0] === 'MAX(r.postdate)');

        if ($isAggregateOrder) {
            $innerOrderBy = 'latest_postdate';
            $innerExtraGroupBy = '';
            $outerOrderBy = 'stats.latest_postdate';
        } else {
            // orderField is like 'm.title', 'm.year', 'm.rating'
            $innerOrderBy = $order[0];
            $innerExtraGroupBy = ', '.$order[0];
            $outerOrderBy = $order[0];
        }

        $moviesSql = 'SELECT m.imdbid, m.tmdbid, m.traktid, m.title, m.year, m.rating, '
            .'m.plot, m.genre, m.director, m.actors, m.cover, '
            .'stats.latest_postdate, stats.total_releases '
            .'FROM ('
            .'SELECT m.imdbid, MAX(r.postdate) AS latest_postdate, COUNT(r.id) AS total_releases '
            .'FROM movieinfo m '
            .'INNER JOIN releases r ON r.imdbid = m.imdbid '
            .'WHERE '.$baseWhere.' '
            .'GROUP BY m.imdbid'.$innerExtraGroupBy.' '
            ."ORDER BY {$innerOrderBy} {$order[1]} "
            ."LIMIT {$num} OFFSET {$start}"
            .') stats '
            .'INNER JOIN movieinfo m ON m.imdbid = stats.imdbid '
            ."ORDER BY {$outerOrderBy} {$order[1]}";

        $movies = MovieInfo::fromQuery($moviesSql);

        if ($movies->isEmpty()) {
            return collect();
        }

        // Build list of movie IMDB IDs for release query
        $movieImdbIds = $movies->pluck('imdbid')->toArray();

        // Step 3: Get the top 2 releases per movie without issuing one query per movie.
        $rankedReleases = DB::table('releases as r')
            ->select(['r.id', 'r.imdbid', 'r.guid', 'r.searchname', 'r.completion', 'r.repair_outcome', 'r.rescan_outcome', 'r.size', 'r.postdate', 'r.adddate', 'r.haspreview'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY r.imdbid ORDER BY r.postdate DESC) AS release_rank')
            ->whereIn('r.imdbid', $movieImdbIds)
            ->whereRaw("r.passwordstatus {$this->showPasswords}");

        if ($catArray !== []) {
            $rankedReleases->whereIn('r.categories_id', $catArray);
        } elseif ($excludedCats !== []) {
            $rankedReleases->whereNotIn('r.categories_id', $excludedCats);
        }

        if ($maxAge > 0) {
            $rankedReleases->where('r.postdate', '>', now()->subDays($maxAge));
        }

        $releases = DB::query()
            ->fromSub($rankedReleases, 'ranked_releases')
            ->where('release_rank', '<=', 2)
            ->get();

        // Group by imdbid
        $releasesByMovie = [];
        foreach ($releases as $release) {
            $releasesByMovie[$release->imdbid][] = $release;
        }

        // Attach releases to each movie
        foreach ($movies as $movie) {
            $movie->releases = $releasesByMovie[$movie->imdbid] ?? []; // @phpstan-ignore assign.propertyReadOnly
        }

        // Set total count on first item (matches existing pattern used by controllers)
        if ($movies->isNotEmpty()) {
            $movies[0]->_totalcount = $totalCount; // @phpstan-ignore property.notFound
        }

        Cache::put($cacheKey, $movies, $expiresAt);

        $this->loadPreviewDataForMovies($movies);

        return $movies;
    }

    /**
     * Get all releases for a single movie by IMDB ID.
     *
     * @param  array<string, mixed>  $excludedCats
     * @return array<int, object>
     */
    public function getMovieReleases(string $imdbid, array $excludedCats = []): array
    {
        $whereExcluded = count($excludedCats) > 0 ? ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')' : '';
        $quotedId = escapeString($imdbid);

        $sql = 'SELECT r.id, r.guid, r.searchname, r.completion, r.repair_outcome, r.rescan_outcome, r.size, r.postdate, r.adddate, r.haspreview '
            .'FROM releases r '
            .'WHERE r.imdbid = '.$quotedId.' '
            ."AND r.passwordstatus {$this->showPasswords} "
            .$whereExcluded.' '
            .'ORDER BY r.postdate DESC';

        $releases = DB::select($sql);
        $this->previewDataLoader->load($releases);

        return $releases;
    }

    /**
     * Get the order type the user requested on the movies page.
     *
     * @return array{0: string, 1: string}
     */
    protected function getMovieOrder(string $orderBy): array
    {
        $orderArr = explode('_', (($orderBy === '') ? 'MAX(r.postdate)' : $orderBy));
        $orderField = match ($orderArr[0]) {
            'title' => 'm.title',
            'year' => 'm.year',
            'rating' => 'm.rating',
            default => 'MAX(r.postdate)',
        };

        return [$orderField, isset($orderArr[1]) && preg_match('/^asc|desc$/i', $orderArr[1]) ? $orderArr[1] : 'desc'];
    }

    /**
     * Order types for movies page.
     *
     * @return array<int, string>
     */
    public function getMovieOrdering(): array
    {
        return ['title_asc', 'title_desc', 'year_asc', 'year_desc', 'rating_asc', 'rating_desc'];
    }

    protected function getBrowseBy(): string
    {
        $browseBy = ' ';
        $browseByArr = ['genre', 'rating', 'imdb'];
        foreach ($browseByArr as $bb) {
            if (request()->has($bb) && ! empty(request()->input($bb))) {
                $bbv = request()->input($bb);
                if (is_array($bbv)) {
                    continue;
                }
                $bbv = stripslashes((string) $bbv);
                if ($bb === 'rating') {
                    if (preg_match('/^[1-9]$/', $bbv) === 1) {
                        $browseBy .= ' AND CAST(NULLIF(m.rating, \'\') AS DECIMAL(4, 1)) >= '.(int) $bbv;
                    }

                    continue;
                }
                if ($bb === 'imdb') {
                    $browseBy .= ' AND m.imdbid = '.escapeString($bbv);
                } else {
                    $browseBy .= ' AND m.'.$bb.' '.'LIKE '.escapeString('%'.$bbv.'%');
                }
            }
        }

        $yearRange = YearRange::fromInput(
            request()->input('year'),
            request()->input('year_from'),
            request()->input('year_to'),
        );
        if ($yearRange !== null) {
            if ($yearRange->from !== null && $yearRange->to !== null && $yearRange->from === $yearRange->to) {
                $browseBy .= ' AND m.year = '.escapeString((string) $yearRange->from);
            } elseif ($yearRange->from !== null && $yearRange->to !== null) {
                $browseBy .= ' AND m.year BETWEEN '.escapeString((string) $yearRange->from).' AND '.escapeString((string) $yearRange->to);
            } elseif ($yearRange->from !== null) {
                $browseBy .= ' AND m.year >= '.escapeString((string) $yearRange->from);
            } elseif ($yearRange->to !== null) {
                $browseBy .= ' AND m.year <= '.escapeString((string) $yearRange->to);
            }
        }

        return $browseBy;
    }

    private function getTextSearchWhere(MovieSearchQuery $searchQuery): string
    {
        $where = '';
        $searchableFields = ['title', 'actors', 'director', 'plot'];

        foreach ($searchQuery->termsByField() as $field => $terms) {
            foreach ($terms as $term) {
                $like = escapeString('%'.$term.'%');
                if ($field === 'all') {
                    $clauses = array_map(
                        static fn (string $searchableField): string => "m.{$searchableField} LIKE {$like}",
                        $searchableFields,
                    );
                    $where .= ' AND ('.implode(' OR ', $clauses).')';

                    continue;
                }

                if (in_array($field, $searchableFields, true)) {
                    $where .= " AND m.{$field} LIKE {$like}";
                }
            }
        }

        return $where;
    }

    /**
     * Get IMDB genres.
     *
     * @return array<int, string>
     */
    public function getGenres(): array
    {
        return [
            'Action',
            'Adventure',
            'Animation',
            'Biography',
            'Comedy',
            'Crime',
            'Documentary',
            'Drama',
            'Family',
            'Fantasy',
            'Film-Noir',
            'Game-Show',
            'History',
            'Horror',
            'Music',
            'Musical',
            'Mystery',
            'News',
            'Reality-TV',
            'Romance',
            'Sci-Fi',
            'Sport',
            'Talk-Show',
            'Thriller',
            'War',
            'Western',
        ];
    }
}
