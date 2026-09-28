<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\MovieFilmFilters;
use App\Data\MovieFilmTile;
use App\Data\MovieFilmWallFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Films wall's queries (docs/proposals/movies-redesign/DATA-CONTRACT.md 4.2). A film is listed
 * when it has at least one release in the Movies categories the viewer may see (a per-film scalar
 * probe, as TvShowWall). "Newest releases first" and "Newest to the site first" take their key from
 * the index-only group-by on the per-film index over all of a film's Movies releases, whatever the
 * viewer may see, as the TV wall does; "Newest films first" is the stored year, then the newest
 * release; ties go to the title ignoring case, then the id. Nothing per film is stored.
 */
final class MovieFilmWall
{
    /** Page 1 takes the films in sort order this many at a time and keeps the visible ones. */
    public const int BATCH = 100;

    private const int MENU_SECONDS = 3600;

    public function __construct(private readonly ReleaseBrowseService $releases, private readonly MovieReleaseList $list) {}

    /**
     * The film bar's menus, the same for every user: the Movie releases list's Genre, MPAA Rating
     * and Language options, with Language ordered by the number of films with a Movies release,
     * ties by name (the list orders it by releases).
     *
     * @return array{genre: array<int, string>, rating: array<string, string>, language: array<string, string>, language_codes: array<string, list<string>>}
     */
    public function options(): array
    {
        $options = $this->list->filmOptions();
        $films = Cache::remember('movie_films_language_counts', self::MENU_SECONDS, fn (): array => $this->probe(DB::table('movieinfo as m'))
            ->where('m.original_language', '<>', '')->groupBy('m.original_language')
            ->selectRaw('m.original_language AS code, COUNT(*) AS films')->get()
            ->mapWithKeys(static fn (object $row): array => [(string) $row->code => (int) $row->films])->all());
        $count = static fn (string $code): int => array_sum(array_map(static fn (string $same): int => $films[$same] ?? 0, $options['language_codes'][$code] ?? [$code]));
        $codes = array_map('strval', array_keys($options['language']));
        usort($codes, static fn (string $a, string $b): int => [$count($b), $options['language'][$a]] <=> [$count($a), $options['language'][$b]]);
        $options['language'] = array_combine($codes, array_map(static fn (string $code): string => $options['language'][$code], $codes));

        return $options;
    }

    /** @param list<int> $exclusions */
    public function count(MovieFilmWallFilters $filters, array $exclusions): int
    {
        return (int) Cache::remember($this->cacheKey('movie_films_count', $filters->countKey(), $exclusions), $this->ttl(),
            fn (): int => $this->whereVisible($this->whereMatching(DB::table('movieinfo as m'), $filters), $exclusions)->count());
    }

    /**
     * The ids of the requested page in display order. Page 1 reads the films in sort order a
     * batch at a time: the films up to the BATCH-th one's sort value (every film sharing it
     * included), their visible ones sorted, until 42 pass; a deeper page probes every film
     * before it.
     *
     * @param  list<int>  $exclusions
     * @return list<int>
     */
    public function pageIds(MovieFilmWallFilters $filters, array $exclusions, int $total): array
    {
        $offset = ($filters->page - 1) * MovieFilmWallFilters::PER_PAGE;
        if ($offset >= $total) {
            return [];
        }
        if ($filters->page > 1) {
            return $this->ids($this->ordered($filters, $exclusions)->offset($offset));
        }
        for ($batch = 1; ; $batch++) {
            $bound = $this->boundary($filters, $batch * self::BATCH);
            $page = $this->ordered($filters, $exclusions);
            if ($bound !== null) {
                match ($filters->sort) {
                    'recent', 'newsite' => $page->where('g.sort_key', '>=', $bound),
                    'year' => $page->where('m.year', '>=', $bound),
                    default => $page->whereRaw($this->title().' <= ?', [$bound]),
                };
            }
            $ids = $this->ids($page);
            if ($bound === null || count($ids) === MovieFilmWallFilters::PER_PAGE) {
                return $ids;
            }
        }
    }

    /**
     * The page's tiles: `Year · two genres` (TMDB's order, a genre the Genre filter matched first),
     * the score line and the viewer's visible releases, counted for the page in one query.
     *
     * @param  list<int>  $ids  in display order
     * @param  list<int>  $matched  the Genre filter's ticked genres
     * @param  list<int>  $exclusions
     * @return list<MovieFilmTile>
     */
    public function tiles(array $ids, array $matched, array $exclusions): array
    {
        if ($ids === []) {
            return [];
        }
        $films = DB::table('movieinfo')->whereIn('id', $ids)
            ->get(['id', 'imdbid', 'title', 'year', 'rating', 'vote_count', 'content_rating_us'])->keyBy('id');
        $genres = [];
        foreach (DB::table('movie_genres as mg')->join('genres as g', 'g.id', '=', 'mg.genres_id')->whereIn('mg.movieinfo_id', $ids)
            ->orderBy('mg.movieinfo_id')->orderBy('mg.position')->get(['mg.movieinfo_id', 'g.id', 'g.title']) as $row) {
            $genres[(int) $row->movieinfo_id][] = ['matched' => in_array((int) $row->id, $matched, true), 'title' => (string) $row->title];
        }
        $counts = $this->visibleReleases($ids, $exclusions);
        $tiles = [];
        foreach ($ids as $id) {
            $film = $films->get($id);
            if ($film === null) {
                continue;
            }
            $own = $genres[$id] ?? [];
            usort($own, static fn (array $a, array $b): int => $b['matched'] <=> $a['matched']);
            $releases = $counts[$id] ?? 0;
            $imdbId = (string) $film->imdbid;
            $tiles[] = new MovieFilmTile(
                id: $id,
                title: (string) $film->title,
                url: url('/movies/film/'.$id),
                poster: $imdbId === '' ? null : getImageAssetUrl('movies', $imdbId.'-cover'),
                year: preg_match('/^\d{4}$/', (string) $film->year) === 1 ? (string) $film->year : '',
                genres: array_column(array_slice($own, 0, 2), 'title'),
                scoreLine: self::scoreLine((string) $film->rating, $film->vote_count === null ? null : (int) $film->vote_count, (string) $film->content_rating_us),
                releaseCount: number_format($releases).' '.($releases === 1 ? 'release' : 'releases'),
            );
        }

        return $tiles;
    }

    /**
     * The tile's score line (DATA-CONTRACT 2.2): the score as stored, whole numbers whole, or
     * "Too few votes" (no score, a score of 0, or under 10 votes), then the MPAA rating if any.
     */
    public static function scoreLine(string $rating, ?int $votes, string $certificate): string
    {
        $score = (float) $rating;
        $few = trim($rating) === '' || $score <= 0.0 || ($votes !== null && $votes < MovieFilmFilters::MIN_VOTES);

        return implode(' · ', array_filter([$few ? 'Too few votes' : (string) $score, $certificate], static fn (string $part): bool => $part !== ''));
    }

    public function personName(int $id): ?string
    {
        $name = DB::table('people')->where('id', $id)->value('name');

        return is_string($name) ? $name : null;
    }

    /**
     * Keeps the films (`$film`, a movieinfo.id column) with a Movies release the user may see:
     * the password setting and the user's excluded categories. A scalar probe, not EXISTS
     * (DATA-CONTRACT fact 7).
     *
     * @param  list<int>  $exclusions
     */
    public function whereVisible(Builder $query, array $exclusions, string $film = 'm.id'): Builder
    {
        return $this->probe($query, $film, $exclusions, $this->releases->showPasswords());
    }

    /**
     * The films with at least one Movies release; with a password rule, only releases it allows
     * and outside the exclusions count.
     *
     * @param  list<int>  $exclusions
     */
    private function probe(Builder $query, string $film = 'm.id', array $exclusions = [], ?string $password = null): Builder
    {
        return $query->where(static function (Builder $probe) use ($exclusions, $password, $film): void {
            self::whereCounted($probe->selectRaw('1')->from('releases as r')->whereColumn('r.movieinfo_id', $film), 'r.', $exclusions, $password)->limit(1);
        }, '=', 1);
    }

    /**
     * The releases that count for a film: in the Movies categories and, with a password rule,
     * only those it allows and outside the exclusions. `$releases` prefixes the columns.
     *
     * @param  list<int>  $exclusions
     */
    private static function whereCounted(Builder $query, string $releases, array $exclusions, ?string $password): Builder
    {
        $query->whereBetween($releases.'categories_id', MovieReleaseList::BAND_CATEGORIES);
        if ($password !== null) {
            $query->whereRaw($releases.'passwordstatus '.$password);
        }
        if ($exclusions !== []) {
            $query->whereNotIn($releases.'categories_id', $exclusions);
        }

        return $query;
    }

    /**
     * The viewer's visible Movies releases per film, for a page of films.
     *
     * @param  list<int>  $ids
     * @param  list<int>  $exclusions
     * @return array<int, int>
     */
    private function visibleReleases(array $ids, array $exclusions): array
    {
        $query = DB::table('releases');
        if ($this->isMariaDb()) {
            $query->forceIndex(MovieReleaseList::FILM_INDEX);
        }
        self::whereCounted($query->whereIn('movieinfo_id', $ids), '', $exclusions, $this->releases->showPasswords());

        return $query->groupBy('movieinfo_id')->selectRaw('movieinfo_id, COUNT(*) AS releases')->get()
            ->mapWithKeys(static fn (object $row): array => [(int) $row->movieinfo_id => (int) $row->releases])->all();
    }

    /**
     * The sort value of the `$nth` matching film in sort order, visible or not; null when fewer
     * films match. Page 1 reads the films up to it.
     */
    private function boundary(MovieFilmWallFilters $filters, int $nth): ?string
    {
        // Read from the per-film dates alone, or the films alone, where nothing needs the other:
        // a film without a Movies release only makes the batch smaller.
        [$query, $column, $expression] = match (true) {
            $filters->sort === 'year' => [$this->whereMatching(DB::table('movieinfo as m'), $filters), 'm.year', 'm.year DESC'],
            $filters->sort === 'az' => [$this->whereMatching(DB::table('movieinfo as m'), $filters), 'm.title', $this->title()],
            $filters->any() => [$this->sorted($filters), 'g.sort_key', 'g.sort_key DESC'],
            default => [DB::query()->fromSub($this->releaseDates($filters->sort === 'recent' ? 'MAX(postdate)' : 'MIN(adddate)'), 'g'), 'g.sort_key', 'g.sort_key DESC'],
        };
        $value = $query->orderByRaw($expression)->offset($nth - 1)->limit(1)->value($column);

        return $value === null ? null : (string) $value;
    }

    /**
     * The visible matching films in display order.
     *
     * @param  list<int>  $exclusions
     */
    private function ordered(MovieFilmWallFilters $filters, array $exclusions): Builder
    {
        $query = $this->whereVisible($this->sorted($filters), $exclusions)->select('m.id');
        if (in_array($filters->sort, ['recent', 'newsite'], true)) {
            $query->orderByDesc('g.sort_key');
        } elseif ($filters->sort === 'year') {
            $query->orderByDesc('m.year')->orderByDesc('g.sort_key');
        }

        return $query->orderByRaw($this->title())->orderBy('m.id');
    }

    /**
     * The matching films with their sort key. The date sorts read the per-film dates first and
     * join each film to them (STRAIGHT_JOIN; MariaDB would otherwise probe every film before
     * sorting, about 40 ms), except with a person, whose few films MariaDB reads first and dates
     * alone (about 2 ms). "Newest films first" joins the newest release for its ties.
     */
    private function sorted(MovieFilmWallFilters $filters): Builder
    {
        $dates = $this->releaseDates($filters->sort === 'newsite' ? 'MIN(adddate)' : 'MAX(postdate)');
        $query = match (true) {
            $filters->sort === 'az' => DB::table('movieinfo as m'),
            $filters->sort === 'year' || $filters->person !== null => DB::table('movieinfo as m')->joinSub($dates, 'g', 'g.movieinfo_id', '=', 'm.id'),
            default => $this->datesFirst($dates),
        };

        return $this->whereMatching($query, $filters);
    }

    /** The film bar's conditions and the person on `m`. */
    private function whereMatching(Builder $query, MovieFilmWallFilters $filters): Builder
    {
        $this->list->whereFilms($query, $filters->films);
        if ($filters->person !== null) {
            $query->whereExists(static fn (Builder $exists) => $exists->selectRaw('1')->from('movie_people as mp')
                ->whereColumn('mp.movieinfo_id', 'm.id')->where('mp.people_id', $filters->person));
        }

        return $query;
    }

    private function datesFirst(Builder $dates): Builder
    {
        return DB::query()->fromRaw('('.$dates->toSql().') AS g '.($this->isMariaDb() ? 'STRAIGHT_JOIN' : 'INNER JOIN').' movieinfo AS m ON m.id = g.movieinfo_id',
            $dates->getBindings());
    }

    /** One sort key per film from its Movies releases, answered from the per-film index alone. */
    private function releaseDates(string $aggregate): Builder
    {
        $query = DB::table('releases');
        if ($this->isMariaDb()) {
            $query->forceIndex(MovieReleaseList::FILM_INDEX);
        }

        return $query->select('movieinfo_id')->selectRaw($aggregate.' AS sort_key')->where('movieinfo_id', '>', 0)
            ->whereBetween('categories_id', MovieReleaseList::BAND_CATEGORIES)->groupBy('movieinfo_id');
    }

    /** The title ignoring case: MariaDB's collation already does; SQLite needs NOCASE. */
    private function title(): string
    {
        return $this->isMariaDb() ? 'm.title' : 'm.title COLLATE NOCASE';
    }

    /** @return list<int> */
    private function ids(Builder $query): array
    {
        return $query->limit(MovieFilmWallFilters::PER_PAGE)->pluck('m.id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    private function isMariaDb(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** @param list<int> $exclusions */
    private function cacheKey(string $prefix, string $filters, array $exclusions): string
    {
        sort($exclusions);

        return $prefix.':'.ReleaseBrowseService::cacheVersion().':'.md5($filters.'|'.implode(',', $exclusions).'|'.$this->releases->showPasswords());
    }

    private function ttl(): \DateTimeInterface
    {
        return now()->addMinutes(max(1, (int) config('nntmux.cache_expiry_short', 5)) * 2);
    }
}
