<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\MovieFilmFilters;
use App\Data\MovieReleaseFilters;
use App\Data\ReleaseListFilters;
use App\Models\Category;
use App\Support\LanguageNames;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Movie releases screen's queries (BandReleaseList, docs/proposals/movies-redesign/DATA-CONTRACT.md
 * 4.1). With any film filter set, the film filters drive the read: the matching films
 * (`movieinfo`) joined in that order (STRAIGHT_JOIN) to their releases on the per-film index,
 * the band as `categories_id BETWEEN 2000 AND 2999`, the release filters as conditions; the
 * same cost on every page, so never mirrored. Left to its own plan MariaDB reads every release.
 * Otherwise the release index.
 *
 * @extends BandReleaseList<MovieReleaseFilters>
 */
final class MovieReleaseList extends BandReleaseList
{
    /** The per-film index the film filters read releases from. */
    public const string FILM_INDEX = 'ix_releases_movieinfo_cat';

    /** The band as the per-film index holds it (it has no category_band): the Movies categories_id range. */
    public const array BAND_CATEGORIES = [Category::MOVIE_ROOT, Category::MOVIE_ROOT + 999];

    /** The Category menu's order (SPEC 5.2); other sub-categories with releases follow. */
    public const CATEGORY_ORDER = [Category::MOVIE_HD, Category::MOVIE_UHD, Category::MOVIE_SD, Category::MOVIE_BLURAY, Category::MOVIE_DVD,
        Category::MOVIE_3D, Category::MOVIE_X265, Category::MOVIE_FOREIGN, Category::MOVIE_OTHER];

    protected function band(): int
    {
        return Category::MOVIE_ROOT;
    }

    protected function cachePrefix(): string
    {
        return 'movie_releases';
    }

    /** @param MovieReleaseFilters $filters */
    protected function countIndex(ReleaseListFilters $filters): string
    {
        return $filters->films->any() ? self::FILM_INDEX : 'ix_releases_band_count';
    }

    /**
     * @param  MovieReleaseFilters  $filters
     * @param  list<int>  $exclusions
     */
    public function readIndex(ReleaseListFilters $filters, array $exclusions): string
    {
        return $filters->films->any() ? self::FILM_INDEX : $this->releaseIndex($filters);
    }

    /**
     * The Category menu: the Movies sub-categories the user may see that hold releases (counted
     * for all users) or are chosen, in the order HD, UHD, SD, BluRay, DVD, 3D, X265, Foreign,
     * Other, then any other such sub-category.
     *
     * @param  list<int>  $exclusions
     * @param  list<int>  $chosen  the sub-categories the URL or the remembered filters tick
     * @return array<int, string> id => title, in menu order
     */
    public function categoryMenu(array $exclusions, array $chosen = []): array
    {
        return $this->orderedCategoryMenu(self::CATEGORY_ORDER, $exclusions, $chosen);
    }

    /**
     * The film bar's menus, the same for every user and kept for an hour: Genre (type 2000
     * genres that have a film, A to Z), MPAA Rating (the ratings present, in the fixed order)
     * and Language (the films' original languages by their Movies releases, most first, ties by
     * name; codes that share a name are one option, valued by the code with the most releases).
     *
     * @return array{genre: array<int, string>, rating: array<string, string>, language: array<string, string>, language_codes: array<string, list<string>>}
     */
    public function filmOptions(): array
    {
        return Cache::remember($this->cachePrefix().'_film_options', self::MENU_SECONDS, function (): array {
            $genres = DB::table('genres as g')->where('g.type', Category::MOVIE_ROOT)
                ->whereExists(static fn (Builder $films) => $films->selectRaw('1')->from('movie_genres as mg')->whereColumn('mg.genres_id', 'g.id'))
                ->orderBy('g.title')->orderBy('g.id')->get(['g.id', 'g.title']);
            $present = DB::table('movieinfo')->where('content_rating_us', '<>', '')->distinct()->pluck('content_rating_us')->map(static fn (mixed $rating): string => (string) $rating)->all();
            $ratings = array_values(array_intersect(MovieFilmFilters::RATINGS, $present));

            $byName = [];
            $perLanguage = $this->filmReleases()->where('m.original_language', '<>', '')->groupBy('m.original_language')
                ->selectRaw('m.original_language AS code, COUNT(*) AS releases')->get();
            foreach ($perLanguage->sortByDesc('releases') as $row) {
                $name = LanguageNames::name((string) $row->code);
                if ($name !== null) {
                    $byName[$name] ??= ['code' => (string) $row->code, 'name' => $name, 'releases' => 0, 'codes' => []];
                    $byName[$name]['releases'] += (int) $row->releases;
                    $byName[$name]['codes'][] = (string) $row->code;
                }
            }
            $names = array_values($byName);
            usort($names, static fn (array $a, array $b): int => [$b['releases'], $a['name']] <=> [$a['releases'], $b['name']]);

            return [
                'genre' => $genres->mapWithKeys(static fn (object $genre): array => [(int) $genre->id => (string) $genre->title])->all(),
                'rating' => array_combine($ratings, $ratings),
                'language' => array_column($names, 'name', 'code'),
                'language_codes' => array_column($names, 'codes', 'code'),
            ];
        });
    }

    /**
     * @param  MovieReleaseFilters  $filters
     * @param  list<int>  $exclusions
     */
    protected function visible(ReleaseListFilters $filters, array $exclusions, string $index): Builder
    {
        if ($index === self::FILM_INDEX) {
            return $this->whereRelease($this->whereFilms($this->filmReleases(), $filters->films), $filters, $exclusions);
        }
        $query = DB::table('releases');
        if ($this->isMariaDb()) {
            $query->forceIndex($index);
        }

        return $this->whereRelease($query->where('releases.category_band', $this->band()), $filters, $exclusions);
    }

    /**
     * Every film (`m`) joined in that order to its Movies releases on the per-film index, which
     * has no category_band: the band is its categories_id range.
     */
    private function filmReleases(): Builder
    {
        $query = $this->isMariaDb()
            ? DB::query()->from(DB::raw('movieinfo AS m STRAIGHT_JOIN releases FORCE INDEX ('.self::FILM_INDEX.') ON releases.movieinfo_id = m.id'))
            : DB::table('movieinfo as m')->join('releases', 'releases.movieinfo_id', '=', 'm.id');

        return $query->whereBetween('releases.categories_id', self::BAND_CATEGORIES);
    }

    /**
     * The film bar's conditions on `m` (DATA-CONTRACT 4.1): Genre as EXISTS on movie_genres;
     * Year on the stored four-digit year; Score as bands of the stored rating, a film with fewer
     * than MIN_VOTES votes, or a score empty or 0, in "Too few votes" (a film whose count is not
     * stored yet is banded by its score alone; DATA-CONTRACT 2.2); MPAA Rating and Language on
     * their columns.
     */
    public function whereFilms(Builder $query, MovieFilmFilters $films): Builder
    {
        if ($films->genres !== []) {
            $query->whereExists(static fn (Builder $genre) => $genre->selectRaw('1')->from('movie_genres as mg')
                ->whereColumn('mg.movieinfo_id', 'm.id')->whereIn('mg.genres_id', $films->genres));
        }
        if (($bounds = $films->yearBounds()) !== null) {
            $query->whereBetween('m.year', [(string) $bounds[0], (string) $bounds[1]]);
        } elseif ($films->decades !== []) {
            $query->where(static function (Builder $any) use ($films): void {
                foreach ($films->decades as $decade) {
                    $any->orWhereBetween('m.year', [(string) $decade, (string) ($decade + 9)]);
                }
            });
        }
        if ($films->scores !== []) {
            $query->where(static function (Builder $any) use ($films): void {
                foreach ($films->scores as $score) {
                    $any->orWhere(static fn (Builder $band) => self::whereScore($band, $score));
                }
            });
        }
        if ($films->ratings !== []) {
            $query->whereIn('m.content_rating_us', $films->ratings);
        }
        if ($films->languages !== []) {
            $query->whereIn('m.original_language', $films->languageValues());
        }

        return $query;
    }

    private static function whereScore(Builder $band, string $score): void
    {
        if ($score === 'few') {
            $band->where('m.rating', '')->orWhereRaw('m.rating + 0 = 0')->orWhere('m.vote_count', '<', MovieFilmFilters::MIN_VOTES);

            return;
        }
        $band->where('m.rating', '<>', '')->whereRaw('m.rating + 0 > 0')
            ->where(static fn (Builder $votes) => $votes->whereNull('m.vote_count')->orWhere('m.vote_count', '>=', MovieFilmFilters::MIN_VOTES));
        if ($score === 'low') {
            $band->whereRaw('m.rating + 0 < 5');

            return;
        }
        $band->whereRaw('m.rating + 0 >= ?', [(int) $score]);
        if ($score !== '9') {
            $band->whereRaw('m.rating + 0 < ?', [(int) $score + 1]);
        }
    }
}
