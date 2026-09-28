<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\TvShowTile;
use App\Models\Category;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The show page's "Similar shows" (docs/proposals/tv-redesign/SPEC.md 3.3; Movies DATA-CONTRACT.md
 * 4.5), the film page's rule on TV shows: candidates share a TV genre (`video_genres`) or a person
 * (`video_people`) with the show and have a TV release the viewer may see (the wall's per-show
 * probe); score = 2 × shared genres + 3 × shared people − |premiere year gap| / 10, with no year
 * term when either show's premiere year is unknown; the best six, ties to the lower show id.
 */
final class TvSimilarShows
{
    public const LIMIT = 6;

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
        $genres = DB::table('video_genres as mine')->join('genres as g', static function (JoinClause $join): void {
            $join->on('g.id', '=', 'mine.genres_id')->where('g.type', '=', Category::TV_ROOT);
        })->join('video_genres as other', 'other.genres_id', '=', 'mine.genres_id')
            ->where('mine.videos_id', $videosId)->where('other.videos_id', '<>', $videosId)
            ->selectRaw('other.videos_id AS videos_id, 1 AS genres, 0 AS people');
        $people = DB::table('video_people as mine')->join('video_people as other', 'other.people_id', '=', 'mine.people_id')
            ->where('mine.videos_id', $videosId)->where('other.videos_id', '<>', $videosId)
            ->selectRaw('other.videos_id AS videos_id, 0 AS genres, 1 AS people');
        $shared = DB::query()->fromSub($genres->unionAll($people), 'shared')
            ->groupBy('shared.videos_id')->selectRaw('shared.videos_id, SUM(shared.genres) AS genres, SUM(shared.people) AS people');

        $candidates = $this->wall->whereVisible(
            DB::query()->fromSub($shared, 's')->join('videos as v', 'v.id', '=', 's.videos_id')->leftJoin('tv_info as t', 't.videos_id', '=', 'v.id')
                ->where('v.type', 0)->select(['v.id', 's.genres', 's.people', 't.premiered', 'v.started']),
            $exclusions,
        )->get();

        $scored = [];
        foreach ($candidates as $candidate) {
            $other = TvShowWall::year($candidate->premiered, $candidate->started);
            $gap = $year === null || $other === null ? 0 : abs($year - $other) / 10;
            $scored[] = ['id' => (int) $candidate->id, 'score' => 2 * (int) $candidate->genres + 3 * (int) $candidate->people - $gap];
        }
        usort($scored, static fn (array $a, array $b): int => [$b['score'], $a['id']] <=> [$a['score'], $b['id']]);

        return array_column(array_slice($scored, 0, self::LIMIT), 'id');
    }
}
