<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseBrowserState;
use App\Data\ReleaseCoverItem;
use App\Enums\ReleaseSort;
use App\Models\User;
use App\Support\CoverBrowseResults;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class TvEpisodeBrowser
{
    public function __construct(private readonly ReleaseBrowserQuery $browser, private readonly ReleaseBrowseService $rows) {}

    public function paginate(ReleaseBrowserState $state, User $user): CoverBrowseResults
    {
        $query = $this->browser->matchingQuery($state, $user)->whereNotNull('m.id')->where('m.title', '!=', '');

        return TvBrowseMembershipTable::read($query, function (TvBrowseMembershipTable $table) use ($state): CoverBrowseResults {
            $sort = ReleaseSort::resolve($state->sort);
            [$column, $direction] = $sort->order(grouped: true);
            if ($sort === ReleaseSort::Name) {
                $column = 'MIN(membership.sort_name)';
            }
            $groups = $table->members()->join('releases as r', 'r.id', '=', 'membership.release_id')
                ->select('membership.episode_id')->selectRaw('COUNT(*) AS release_count')->groupBy('membership.episode_id');
            if ($state->trending) {
                $groups->leftJoinSub(CoverBrowseScope::recentGrabs(), 'recent', 'recent.releases_id', '=', 'r.id');
                $column = 'SUM(COALESCE(recent.grabs, 0))';
                $direction = 'desc';
            }
            $total = DB::query()->fromSub(clone $groups, 'groups')->count();
            $selected = $groups->orderByRaw($column.' '.$direction)->orderBy('membership.episode_id')
                ->offset(($state->page - 1) * $state->per)->limit($state->per)->get();
            $episodes = DB::table('tv_episodes')->whereIn('id', $selected->pluck('episode_id'))->get()->keyBy('id');
            $ranked = $table->members()->join('releases as r', 'r.id', '=', 'membership.release_id')
                ->whereIn('membership.episode_id', $selected->pluck('episode_id'))->select(['r.*', 'membership.episode_id'])
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY membership.episode_id ORDER BY r.postdate DESC, r.id DESC) AS tile_position');
            $rows = DB::query()->fromSub($ranked, 'ranked')->where('tile_position', '<=', 2)->orderBy('tile_position')->get();
            $this->rows->loadReleaseRows($rows);
            $rows = $rows->groupBy('episode_id');
            $shows = DB::table('videos')->whereIn('id', $episodes->pluck('videos_id'))->get()->keyBy('id');
            $networks = DB::table('tv_info')->whereIn('videos_id', $shows->keys())->pluck('publisher', 'videos_id');
            $covers = collect();
            foreach ($selected as $group) {
                $id = (int) $group->episode_id;
                $episode = $episodes->get($id);
                $show = $shows->get($episode->videos_id);
                $releases = $rows->get($id, collect())->all();
                if ($show === null || $releases === []) {
                    continue;
                }
                $row = $releases[0]->row_data;
                $network = $networks[$show->id] ?? null;
                $covers->push(new ReleaseCoverItem(
                    id: (string) $id, title: $show->title.' · '.sprintf('S%02dE%02d', $episode->series, $episode->episode).(empty($episode->title) ? '' : ' · '.$episode->title),
                    artwork: $row->entity?->artwork, identifyingLine: (string) ($show->genre ?? ''), releaseCount: (int) $group->release_count,
                    metadata: $network ? [(string) $network] : [], releases: $releases,
                    titleUrl: route('title', ['root' => 'tv', 'id' => $show->id]),
                    watchUrl: route('watchlist.picker', ['root' => 'tv', 'id' => $show->id]), watched: $row->watched,
                    watchId: (string) $show->id, genres: (string) ($show->genre ?? ''),
                ));
            }

            return new CoverBrowseResults($covers, $total);
        });
    }

    /** @return LengthAwarePaginator<int, \stdClass> */
    public function releases(ReleaseBrowserState $state, User $user, int $episodeId, int $page = 1, int $per = 24): LengthAwarePaginator
    {
        $episode = DB::table('tv_episodes')->where('id', $episodeId)->first();
        abort_if($episode === null, 404);
        $query = $this->browser->matchingQuery($state, $user)->where('r.videos_id', $episode->videos_id);

        return TvBrowseMembershipTable::read($query, function (TvBrowseMembershipTable $table) use ($episodeId, $page, $per): LengthAwarePaginator {
            $query = $table->members()->join('releases as r', 'r.id', '=', 'membership.release_id')->where('membership.episode_id', $episodeId);
            $total = $query->count();
            $page = min(max(1, $page), max(1, (int) ceil($total / $per)));
            $rows = $query->orderByDesc('r.postdate')->orderByDesc('r.id')->offset(($page - 1) * $per)->limit($per)->get(['r.*']);
            $this->rows->loadReleaseRows($rows);

            return new LengthAwarePaginator($rows, $total, $per, $page);
        });
    }
}
