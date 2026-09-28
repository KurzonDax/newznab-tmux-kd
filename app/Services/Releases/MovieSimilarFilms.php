<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\MovieFilmTile;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * The film page's "Similar films" (docs/proposals/movies-redesign/SPEC.md 5B.3, 6.6;
 * DATA-CONTRACT.md 4.5): candidates share a genre (`movie_genres`) or a person (`movie_people`)
 * with the film and have a Movies release the viewer may see (the Films wall's per-film probe),
 * ranked by SimilarTitles on the stored year. Candidates are every such film in the catalogue.
 */
final class MovieSimilarFilms
{
    public function __construct(private readonly MovieFilmWall $wall) {}

    /**
     * The picks as Films wall tiles, best first; empty when the film has no stored genres or
     * people or nothing shares them.
     *
     * @param  list<int>  $exclusions
     * @return list<MovieFilmTile>
     */
    public function tiles(int $id, ?int $year, array $exclusions): array
    {
        return $this->wall->tiles($this->ids($id, $year, $exclusions), [], $exclusions);
    }

    /**
     * @param  ?int  $year  the film's stored year, null when unknown
     * @param  list<int>  $exclusions
     * @return list<int>
     */
    public function ids(int $id, ?int $year, array $exclusions): array
    {
        // The stored year is four digits or empty (DATA-CONTRACT 2.2); empty means unknown.
        $other = 'CAST(m.year AS INTEGER)';

        return SimilarTitles::best($this->wall->whereVisible(
            DB::query()->fromSub(SimilarTitles::shared('movie_genres', 'movie_people', 'movieinfo_id', Category::MOVIE_ROOT, $id), 's')
                ->join('movieinfo as m', 'm.id', '=', 's.movieinfo_id')->select('m.id'),
            $exclusions,
        ), 'm.id', 'CASE WHEN '.$other.' > 0 THEN '.$other.' END', $year);
    }
}
