<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\MovieFilmHeader;
use App\Data\MovieFilmPageFilters;
use App\Enums\ReleaseResolution;
use App\Models\Category;
use App\Support\LanguageNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The film page's reads (docs/proposals/movies-redesign/DATA-CONTRACT.md 4.4). Every one starts
 * from the film's releases on the per-film index: `movieinfo_id = ?`, the Movies categories
 * (2000–2999, as the Films wall counts them), the password setting and the viewer's excluded
 * categories. The header's count, latest date and best resolution come from all of them, never
 * from the page of the table being shown.
 */
final class MovieFilmPage
{
    /** "Starring" names at most this many people, in TMDB's order (as TV's CAST_LIMIT). */
    public const STARRING_LIMIT = 12;

    /** `movie_people.role` (DATA-CONTRACT 2.4): "Directed by" lists role 0, "Starring" role 1. */
    public const ROLE_DIRECTOR = 0;

    public const ROLE_CAST = 1;

    /** Outside links as today's title page builds them (TitleMetadataLoader): label => URL prefix. */
    private const LINKS = ['IMDb' => 'https://www.imdb.com/title/tt', 'TMDB' => 'https://www.themoviedb.org/movie/', 'Trakt' => 'https://trakt.tv/movies/'];

    public function __construct(private readonly ReleaseBrowseService $releases) {}

    /**
     * The header, or null for an unknown film. A film with no release the viewer may see still
     * has one ("0 releases", no latest or best).
     *
     * @param  list<int>  $exclusions
     */
    public function header(int $id, array $exclusions): ?MovieFilmHeader
    {
        $film = DB::table('movieinfo')->where('id', $id)
            ->first(['imdbid', 'tmdbid', 'traktid', 'title', 'year', 'plot', 'tagline', 'rating', 'vote_count', 'content_rating_us', 'original_language']);
        if ($film === null) {
            return null;
        }
        $stats = $this->visible($id, $exclusions)->selectRaw('COUNT(*) AS releases, MAX(postdate) AS latest, MIN(NULLIF(resolution, 0)) AS best')->first();
        $imdbId = (string) $film->imdbid;
        $score = MovieFilmWall::score((string) $film->rating, $film->vote_count === null ? null : (int) $film->vote_count);
        $people = static fn (int $role): Builder => DB::table('movie_people as mp')->join('people as p', 'p.id', '=', 'mp.people_id')
            ->where('mp.movieinfo_id', $id)->where('mp.role', $role)->orderBy('mp.position');
        $names = static fn (Builder $query): array => $query->pluck('p.name', 'p.id')->map(static fn (mixed $name): string => (string) $name)->all();
        $best = $stats?->best === null ? null : ReleaseResolution::tryFrom((int) $stats->best);

        return new MovieFilmHeader(
            id: $id,
            imdbId: $imdbId,
            title: (string) $film->title,
            year: preg_match('/^\d{4}$/', (string) $film->year) === 1 ? (string) $film->year : '',
            poster: $imdbId === '' ? null : getImageAssetUrl('movies', $imdbId.'-cover'),
            releases: (int) ($stats->releases ?? 0),
            latest: ReleaseRowFacts::date($stats?->latest === null ? null : (string) $stats->latest, CarbonImmutable::now(config('app.timezone', 'UTC'))),
            best: $best === ReleaseResolution::Unknown ? null : $best,
            plot: trim((string) $film->plot),
            genres: DB::table('movie_genres as mg')->join('genres as g', 'g.id', '=', 'mg.genres_id')->where('g.type', Category::MOVIE_ROOT)
                ->where('mg.movieinfo_id', $id)->orderBy('mg.position')->pluck('g.title', 'g.id')->map(static fn (mixed $title): string => (string) $title)->all(),
            tags: array_values(array_filter([
                $score === null ? 'Too few votes' : 'Score '.$score,
                (string) $film->content_rating_us,
                (string) LanguageNames::name((string) $film->original_language),
            ], static fn (string $tag): bool => $tag !== '')),
            directors: $names($people(self::ROLE_DIRECTOR)),
            cast: $names($people(self::ROLE_CAST)->limit(self::STARRING_LIMIT)),
            links: self::links($imdbId, (int) $film->tmdbid, (int) $film->traktid),
            tagline: trim((string) $film->tagline),
        );
    }

    /** Whether the user follows the film (a user_movies row for its IMDb id): the header's Follow film button. */
    public function followed(string $imdbId, int $userId): bool
    {
        return $imdbId !== '' && DB::table('user_movies')->where('users_id', $userId)->where('imdbid', $imdbId)->exists();
    }

    /**
     * The table's rows the filters keep.
     *
     * @param  list<int>  $exclusions
     */
    public function count(int $id, MovieFilmPageFilters $filters, array $exclusions): int
    {
        return $this->filtered($id, $filters, $exclusions)->count();
    }

    /**
     * The ids of the requested page in the table's order: the sorted column, then newest posted
     * first, then the higher id. Resolution sorts on quality, Unknown below SD, so its first
     * (descending) click lists the worst first.
     *
     * @param  list<int>  $exclusions
     * @return list<int>
     */
    public function pageIds(int $id, MovieFilmPageFilters $filters, array $exclusions): array
    {
        return $this->ordered($id, $filters, $exclusions)->offset(($filters->page - 1) * MovieFilmPageFilters::PER_PAGE)
            ->limit(MovieFilmPageFilters::PER_PAGE)->pluck('id')->map(static fn (mixed $release): int => (int) $release)->all();
    }

    /**
     * The page of the table, in its order, that holds a release: where the details page's
     * "All N releases of this film" opens (SPEC 5C.4); null when the viewer may not see it.
     * The film's ids come from the per-film index (DATA-CONTRACT 4.4).
     *
     * @param  list<int>  $exclusions
     */
    public function pageHolding(int $id, int $releaseId, MovieFilmPageFilters $filters, array $exclusions): ?int
    {
        $rank = $this->ordered($id, $filters, $exclusions)->pluck('id')->search(static fn (mixed $release): bool => (int) $release === $releaseId);

        return $rank === false ? null : intdiv((int) $rank, MovieFilmPageFilters::PER_PAGE) + 1;
    }

    /** @return array<string, string> */
    private static function links(string $imdbId, int $tmdbId, int $traktId): array
    {
        $imdb = preg_replace('/^tt/', '', $imdbId) ?? '';

        return array_filter([
            'IMDb' => ctype_digit($imdb) && (int) $imdb > 0 ? self::LINKS['IMDb'].str_pad($imdb, 7, '0', STR_PAD_LEFT).'/' : '',
            'TMDB' => $tmdbId > 0 ? self::LINKS['TMDB'].$tmdbId : '',
            'Trakt' => $traktId > 0 ? self::LINKS['Trakt'].$traktId : '',
        ], static fn (string $url): bool => $url !== '');
    }

    /**
     * The table's order: the sorted column, then newest posted first, then the higher id.
     *
     * @param  list<int>  $exclusions
     */
    private function ordered(int $id, MovieFilmPageFilters $filters, array $exclusions): Builder
    {
        $direction = $filters->ascending ? 'asc' : 'desc';
        $query = $this->filtered($id, $filters, $exclusions);
        match ($filters->sort) {
            'size' => $query->orderBy('size', $direction)->orderByDesc('postdate'),
            'resolution' => $query->orderByRaw('CASE WHEN resolution = 0 THEN 9 ELSE resolution END '.$direction)->orderByDesc('postdate'),
            default => $query->orderBy('postdate', $direction),
        };

        return $query->orderByDesc('id');
    }

    /** @param list<int> $exclusions */
    private function filtered(int $id, MovieFilmPageFilters $filters, array $exclusions): Builder
    {
        $query = $this->visible($id, $exclusions);
        if ($filters->resolutions !== []) {
            $query->whereIn('resolution', $filters->resolutionValues());
        }
        if ($filters->sources !== []) {
            $query->whereIn('source', $filters->sourceValues());
        }

        return $query;
    }

    /** @param list<int> $exclusions */
    private function visible(int $id, array $exclusions): Builder
    {
        $query = DB::table('releases');
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->forceIndex(MovieReleaseList::FILM_INDEX);
        }
        $query->where('movieinfo_id', $id)->whereBetween('categories_id', MovieReleaseList::BAND_CATEGORIES)
            ->whereRaw('passwordstatus '.$this->releases->showPasswords());
        if ($exclusions !== []) {
            $query->whereNotIn('categories_id', $exclusions);
        }

        return $query;
    }
}
