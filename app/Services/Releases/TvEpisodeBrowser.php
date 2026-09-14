<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseBrowserState;
use App\Data\ReleaseCoverItem;
use App\Enums\ReleaseSort;
use App\Models\User;
use App\Support\CoverBrowseResults;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class TvEpisodeBrowser
{
    public function __construct(private readonly ReleaseBrowserQuery $browser, private readonly TvReleaseMembership $membership, private readonly ReleaseBrowseService $rows) {}

    /** @return Collection<int|string, \stdClass> */
    public function episodes(Builder $query): Collection
    {
        return DB::table('tv_episodes')->whereIn('videos_id', (clone $query)->select('r.videos_id')->distinct())
            ->where('episode', '>', 0)->orderBy('id')->get(['id', 'videos_id', 'series', 'episode', 'title'])->unique(fn (object $episode): string => $episode->videos_id.':'.$episode->series.':'.$episode->episode)->keyBy('id');
    }

    public function paginate(ReleaseBrowserState $state, User $user): CoverBrowseResults
    {
        $query = $this->browser->matchingQuery($state, $user)->whereNotNull('m.id')->where('m.title', '!=', '');
        $sort = ReleaseSort::resolve($state->sort);
        $cacheKey = 'tv-episode-groups:'.hash('sha256', serialize([
            DB::connection()->getDatabaseName(), $query->toSql(), $query->getBindings(), $state->sort, $state->trending,
        ]));
        [$episodes, $groups] = Cache::remember($cacheKey, 30, function () use ($query, $sort, $state): array {
            $episodes = $this->episodes($query);
            $episodesByShow = $episodes->groupBy('videos_id');
            /** @var array<int, array{count: int, value: string|int, recent: int, releases: list<\stdClass>}> $groups */
            $groups = [];
            $recentGrabs = $state->trending ? CoverBrowseScope::recentGrabs()->pluck('grabs', 'releases_id') : collect();
            foreach ((clone $query)->orderBy('r.id')->select(['r.id', 'r.videos_id', 'r.tv_episodes_id', 'r.searchname', 'r.display_name', 'r.postdate', 'r.adddate', 'r.grabs'])->lazyById(1000, 'r.id', 'id') as $release) {
                $members = $this->membership->resolve($release, $episodesByShow->get($release->videos_id, collect()));
                $value = match ($sort) {
                    ReleaseSort::PostedNewest, ReleaseSort::PostedOldest => (string) $release->postdate,
                    ReleaseSort::AddedNewest, ReleaseSort::AddedOldest => (string) $release->adddate,
                    ReleaseSort::Name => release_display_name($release),
                    ReleaseSort::Grabs => (int) $release->grabs,
                };
                foreach ($members['episodes'] as $id) {
                    $groups[$id] ??= ['count' => 0, 'value' => $value, 'recent' => 0, 'releases' => []];
                    $group = &$groups[$id];
                    $group['count']++;
                    $group['recent'] += (int) ($recentGrabs[$release->id] ?? 0);
                    $comparison = $sort === ReleaseSort::Name ? strcasecmp((string) $value, (string) $group['value']) : $value <=> $group['value'];
                    if (($sort->order()[1] === 'asc' && $comparison < 0) || ($sort->order()[1] === 'desc' && $comparison > 0)) {
                        $group['value'] = $value;
                    }
                    $group['releases'][] = $release;
                    usort($group['releases'], static fn (object $a, object $b): int => strcmp((string) $b->postdate, (string) $a->postdate) ?: $b->id <=> $a->id);
                    $group['releases'] = array_slice($group['releases'], 0, 2);
                    unset($group);
                }
            }

            return [$episodes, $groups];
        });
        uksort($groups, static function (int $a, int $b) use (&$groups, $sort, $state): int {
            if ($state->trending) {
                return ($groups[$b]['recent'] <=> $groups[$a]['recent']) ?: $a <=> $b;
            }
            $comparison = $sort === ReleaseSort::Name ? strcasecmp((string) $groups[$a]['value'], (string) $groups[$b]['value']) : $groups[$a]['value'] <=> $groups[$b]['value'];

            return ($sort->order()[1] === 'asc' ? $comparison : -$comparison) ?: $a <=> $b;
        });
        $total = count($groups);
        $selected = array_slice($groups, ($state->page - 1) * $state->per, $state->per, true);
        $ids = collect($selected)->flatMap(static fn (array $group): array => array_column($group['releases'], 'id'))->unique();
        $rows = (clone $query)->whereIn('r.id', $ids)->get(['r.*']);
        $this->rows->loadReleaseRows($rows);
        $rows = $rows->keyBy('id');
        $shows = DB::table('videos')->whereIn('id', $episodes->whereIn('id', array_keys($selected))->pluck('videos_id'))->get()->keyBy('id');
        $networks = DB::table('tv_info')->whereIn('videos_id', $shows->keys())->pluck('publisher', 'videos_id');
        $covers = collect();
        foreach ($selected as $id => $group) {
            $episode = $episodes->get($id);
            $show = $shows->get($episode->videos_id);
            $releases = collect($group['releases'])->map(static fn (object $release): ?object => $rows->get($release->id))->filter()->values()->all();
            if ($show === null || $releases === []) {
                continue;
            }
            $row = $releases[0]->row_data;
            $network = $networks[$show->id] ?? null;
            $covers->push(new ReleaseCoverItem(
                id: (string) $id, title: $show->title.' · '.sprintf('S%02dE%02d', $episode->series, $episode->episode).(empty($episode->title) ? '' : ' · '.$episode->title),
                artwork: $row->entity?->artwork, identifyingLine: (string) ($show->genre ?? ''), releaseCount: $group['count'],
                metadata: $network ? [(string) $network] : [], releases: $releases,
                titleUrl: route('title', ['root' => 'tv', 'id' => $show->id]),
                watchUrl: route('watchlist.picker', ['root' => 'tv', 'id' => $show->id]), watched: $row->watched,
                watchId: (string) $show->id, genres: (string) ($show->genre ?? ''),
            ));
        }

        return new CoverBrowseResults($covers, $total);
    }

    /** @return LengthAwarePaginator<int, \stdClass> */
    public function releases(ReleaseBrowserState $state, User $user, int $episodeId, int $page = 1, int $per = 24): LengthAwarePaginator
    {
        $episode = DB::table('tv_episodes')->where('id', $episodeId)->first();
        abort_if($episode === null, 404);
        $query = $this->browser->matchingQuery($state, $user)->where('r.videos_id', $episode->videos_id);
        $episodes = $this->episodes($query);
        $ids = [];
        foreach ((clone $query)->orderByDesc('r.postdate')->orderByDesc('r.id')->select(['r.id', 'r.videos_id', 'r.tv_episodes_id', 'r.searchname'])->cursor() as $release) {
            if (in_array($episodeId, $this->membership->resolve($release, $episodes)['episodes'], true)) {
                $ids[] = $release->id;
            }
        }
        $page = min(max(1, $page), max(1, (int) ceil(count($ids) / $per)));
        $rows = DB::table('releases')->whereIn('id', array_slice($ids, ($page - 1) * $per, $per))->orderByDesc('postdate')->orderByDesc('id')->get();
        $this->rows->loadReleaseRows($rows);

        return new LengthAwarePaginator($rows, count($ids), $per, $page);
    }
}
