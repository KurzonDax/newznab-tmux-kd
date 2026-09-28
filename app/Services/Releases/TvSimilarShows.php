<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\TvShowTile;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * The show page's "Similar shows" (docs/proposals/tv-redesign/SPEC.md 3.3; Movies DATA-CONTRACT.md
 * 4.5), the film page's rule on TV shows: candidates share a TV genre (`video_genres`) or a person
 * (`video_people`) with the show and have a TV release the viewer may see (the wall's per-show
 * probe), ranked by SimilarTitles on the premiere year.
 */
final class TvSimilarShows
{
    public const LIMIT = SimilarTitles::LIMIT;

    public function __construct(private readonly TvShowWall $wall) {}

    /**
     * The picks as wall tiles, best first; empty when the show has no stored genres or cast or
     * nothing shares them.
     *
     * @param  list<int>  $exclusions
     * @return list<TvShowTile>
     */
    public function tiles(int $videosId, ?int $year, array $exclusions): array
    {
        return $this->wall->tiles($this->ids($videosId, $year, $exclusions));
    }

    /**
     * @param  ?int  $year  the show's premiere year (TvShowWall::year())
     * @param  list<int>  $exclusions
     * @return list<int>
     */
    public function ids(int $videosId, ?int $year, array $exclusions): array
    {
        // The candidate's premiere year as the wall reads it (TvShowWall::year()): below 1900 means unknown.
        $other = 'CAST(SUBSTR(COALESCE(t.premiered, v.started), 1, 4) AS INTEGER)';

        return SimilarTitles::best($this->wall->whereVisible(
            DB::query()->fromSub(SimilarTitles::shared('video_genres', 'video_people', 'videos_id', Category::TV_ROOT, $videosId), 's')
                ->join('videos as v', 'v.id', '=', 's.videos_id')->leftJoin('tv_info as t', 't.videos_id', '=', 'v.id')
                ->where('v.type', 0)->select('v.id'),
            $exclusions,
        ), 'v.id', 'CASE WHEN '.$other.' >= 1900 THEN '.$other.' END', $year);
    }
}
