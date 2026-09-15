<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseBrowserState;
use App\Enums\BrowseRoot;
use App\Models\User;
use App\Support\YearRange;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TvShowDirectory
{
    public function __construct(private readonly ReleaseBrowserQuery $browser, private readonly ReleaseBrowseService $rows) {}

    public function state(User $user): ReleaseBrowserState
    {
        return ReleaseBrowserState::fromRequest(new Request(['view' => 'table', 'per' => 24]), BrowseRoot::Tv, $user);
    }

    /** @return array<string, mixed> */
    public function directory(Request $request, User $user, string $initial): array
    {
        $state = $this->state($user);
        $releases = $this->browser->matchingQuery($state, $user)->select('r.videos_id')->selectRaw('COUNT(*) AS release_count')->groupBy('r.videos_id');
        $query = DB::table('videos as v')->leftJoin('tv_info as info', 'info.videos_id', '=', 'v.id')
            ->leftJoinSub($releases, 'available', 'available.videos_id', '=', 'v.id')->where('v.title', '!=', '');
        if (Schema::hasColumn('videos', 'type')) {
            $query->where('v.type', 0);
        }
        $title = $request->string('title')->toString();
        if ($title !== '') {
            $query->whereRaw("v.title LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $title).'%']);
        }
        $year = YearRange::fromInput($request->input('year'), $request->input('year_from'), $request->input('year_to'));
        if ($year?->from !== null) {
            $query->whereRaw('SUBSTR(v.started, 1, 4) >= ?', [(string) $year->from]);
        }
        if ($year?->to !== null) {
            $query->whereRaw('SUBSTR(v.started, 1, 4) <= ?', [(string) $year->to]);
        }
        $initial = $initial === '0-9' ? '#' : strtoupper($initial ?: $request->string('initial')->toString());
        if (preg_match('/^[A-Z#]$/', $initial)) {
            $query->whereRaw($initial === '#' ? 'UPPER(SUBSTR(v.title, 1, 1)) NOT BETWEEN ? AND ?' : 'UPPER(SUBSTR(v.title, 1, 1)) = ?', $initial === '#' ? ['A', 'Z'] : [$initial]);
        }
        if ($request->filled('network')) {
            $query->where('info.publisher', $request->string('network')->toString());
        }
        if ($request->boolean('available')) {
            $query->where('available.release_count', '>', 0);
        }
        if ($request->boolean('watching')) {
            $query->whereIn('v.id', DB::table('user_series')->where('users_id', $user->id)->select('videos_id'));
        }
        $per = $this->per($request);
        $total = $query->count();
        $page = min(max(1, $request->integer('page', 1)), max(1, (int) ceil($total / $per)));
        $shows = $query->select(['v.*', 'info.publisher', 'available.release_count'])->orderBy('v.title')->orderBy('v.id')->offset(($page - 1) * $per)->limit($per)->get();
        $watched = DB::table('user_series')->where('users_id', $user->id)->whereIn('videos_id', $shows->pluck('id'))->pluck('videos_id');
        $seasons = DB::table('tv_episodes')->whereIn('videos_id', $shows->pluck('id'))->where('series', '>', 0)->select('videos_id')->selectRaw('COUNT(DISTINCT series) AS seasons')->groupBy('videos_id')->pluck('seasons', 'videos_id');
        foreach ($shows as $show) {
            $show->watched = $watched->contains($show->id);
            $show->artwork = getImageAssetUrl('tvshows', (string) $show->id);
            $show->seasons = (int) ($seasons[$show->id] ?? 0);
        }

        return ['shows' => new LengthAwarePaginator($shows, $total, $per, $page, ['path' => route('series'), 'query' => [...$request->query(), 'initial' => $initial]]),
            'networks' => DB::table('tv_info')->whereNotNull('publisher')->where('publisher', '!=', '')->distinct()->orderBy('publisher')->pluck('publisher'), 'initial' => $initial];
    }

    /** @return array<string, mixed> */
    public function show(Request $request, User $user, int $id): array
    {
        $title = app(TitleMetadataLoader::class)->load(BrowseRoot::Tv, (string) $id);
        $show = DB::table('videos')->where('id', $id)->first();
        $state = $this->state($user);
        $query = $this->browser->matchingQuery($state, $user)->where('r.videos_id', $id);

        return TvBrowseMembershipTable::read($query, function (TvBrowseMembershipTable $table) use ($request, $user, $id, $title, $show, $state, $query): array {
            $canonical = DB::table('tv_episodes')->where('videos_id', $id)->where('episode', '>', 0)
                ->selectRaw('MIN(id) AS id')->groupBy('series', 'episode');
            $episodes = DB::table('tv_episodes')->whereIn('id', $canonical);
            $seasons = (clone $episodes)->select('series')->distinct()->pluck('series')
                ->merge($table->packs()->distinct()->pluck('season'))->unique()->sort()->values();
            $seasons = $seasons->reject(static fn ($season): bool => (int) $season === 0)->when($seasons->contains(0), static fn ($values) => $values->push(0));
            $season = $request->has('season') ? $request->integer('season') : (int) ($seasons->filter(static fn ($season): bool => $season > 0)->max() ?? 0);
            $per = $this->per($request);
            $kind = $request->string('kind', 'season')->toString();
            abort_unless(in_array($kind, ['season', 'packs', 'episode'], true), 404);
            $episodeId = $request->integer('episode');
            if ($kind === 'packs' || $kind === 'episode') {
                abort_if($kind === 'episode' && ! (clone $episodes)->where('id', $episodeId)->exists(), 404);
                $members = $kind === 'packs'
                    ? $table->packs()->where('season', $season)->select('release_id')
                    : $table->members()->where('full_season', 0)->where('episode_id', $episodeId)->select('release_id');
                $list = (clone $query)->whereIn('r.id', $members);
                $total = $list->count();
                $page = min(max(1, $request->integer('page', 1)), max(1, (int) ceil($total / $per)));
                $rows = $list->orderByDesc('r.postdate')->orderByDesc('r.id')->offset(($page - 1) * $per)->limit($per)->get(['r.*']);
                $this->rows->loadReleaseRows($rows);
                $results = new LengthAwarePaginator($rows, $total, $per, $page);
            } else {
                $list = (clone $episodes)->where('series', $season);
                $total = $list->count();
                $page = min(max(1, $request->integer('page', 1)), max(1, (int) ceil($total / $per)));
                $rows = $list->orderBy('episode')->orderBy('id')->offset(($page - 1) * $per)->limit($per)->get();
                $counts = $table->members()->where('full_season', 0)->whereIn('episode_id', $rows->pluck('id'))
                    ->select('episode_id')->selectRaw('COUNT(*) AS release_count')->groupBy('episode_id')->pluck('release_count', 'episode_id');
                foreach ($rows as $episode) {
                    $episode->release_count = (int) ($counts[$episode->id] ?? 0);
                }
                $results = new LengthAwarePaginator($rows, $total, $per, $page);
            }

            return compact('title', 'show', 'state', 'seasons', 'season', 'results', 'kind', 'episodeId') + [
                'packCount' => $table->packs()->where('season', $season)->count(), 'releaseCount' => $query->count(),
                'watched' => DB::table('user_series')->where('users_id', $user->id)->where('videos_id', $id)->exists(),
            ];
        });
    }

    private function per(Request $request): int
    {
        return in_array($request->integer('per'), [24, 48, 100], true) ? $request->integer('per') : 24;
    }
}
