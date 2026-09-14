<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseRowData;
use App\Services\NfoService;
use App\Support\ReleaseSize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class ReleaseRowDataLoader
{
    /** @param iterable<int, object> $releases */
    public function load(iterable $releases): void
    {
        $items = [];
        foreach ($releases as $release) {
            $items[] = $release;
        }
        $rows = collect($items);
        if ($rows->isEmpty()) {
            return;
        }

        $stored = DB::table('releases')->whereIn('id', $rows->pluck('id'))->get()->keyBy('id');
        $postProcessed = $this->postProcessed($stored);
        $groupIds = $stored->pluck('groups_id')->filter()->unique();
        $groups = $groupIds->isEmpty() ? collect() : DB::table('usenet_groups')->whereIn('id', $groupIds)->pluck('name', 'id');
        $categoryIds = $stored->pluck('categories_id')->filter()->unique();
        $categories = $categoryIds->isEmpty() ? collect() : DB::table('categories as c')
            ->leftJoin('root_categories as root', 'root.id', '=', 'c.root_categories_id')
            ->whereIn('c.id', $categoryIds)->get(['c.id', 'c.title', 'root.title as root_title'])->keyBy('id');
        $entities = app(ReleaseEntityDataLoader::class)->load($stored);
        $basket = [];
        $watchedMovies = [];
        $watchedShows = [];
        if (Auth::id() !== null) {
            $basket = DB::table('users_releases')->where('users_id', Auth::id())->whereIn('releases_id', $rows->pluck('id'))->pluck('releases_id')->map(static fn ($id): int => (int) $id)->all();
            $movieIds = collect($entities)->where('root', 'movies')->pluck('id');
            $showIds = collect($entities)->where('root', 'tv')->pluck('id');
            if ($movieIds->isNotEmpty()) {
                $watchedMovies = DB::table('user_movies')->where('users_id', Auth::id())->whereIn('imdbid', $movieIds)->pluck('imdbid')->map(static fn ($id): string => (string) $id)->all();
            }
            if ($showIds->isNotEmpty()) {
                $watchedShows = DB::table('user_series')->where('users_id', Auth::id())->whereIn('videos_id', $showIds)->pluck('videos_id')->map(static fn ($id): string => (string) $id)->all();
            }
        }
        foreach ($rows as $release) {
            $source = $stored->get($release->id) ?? $release;
            $category = $categories->get($source->categories_id ?? 0);
            $row = new ReleaseRowData(
                id: (int) $release->id,
                guid: (string) ($source->guid ?? ''),
                name: release_display_name($source),
                category: $category === null ? (string) ($release->category_name ?? '') : implode(' > ', array_filter([$category->root_title, $category->title])),
                size: ReleaseSize::format((float) ($source->size ?? 0)),
                files: (int) ($source->totalpart ?? 0),
                added: userDateDiffForHumans($source->adddate ?? null),
                posted: userDate($source->postdate ?? null),
                grabs: (int) ($source->grabs ?? 0),
                comments: (int) ($source->comments ?? 0),
                completion: (float) ($source->completion ?? 0),
                repair_outcome: $source->repair_outcome ?? null,
                rescan_outcome: $source->rescan_outcome ?? null,
                passworded: (int) ($source->passwordstatus ?? -1) > 0,
                has_media_info: (bool) ($release->has_media_info ?? false),
                media_info_summary: $release->media_info_summary ?? null,
                nfo: (int) ($source->nfostatus ?? -1) === NfoService::NFO_FOUND,
                preview: match (true) {
                    (bool) ($release->has_audio_preview ?? false) => 'audio',
                    (bool) ($release->has_video_preview ?? false) => 'video',
                    (int) ($source->haspreview ?? 0) === 1 => 'image',
                    (int) ($source->jpgstatus ?? 0) === 1 => 'sample',
                    default => 'none',
                },
                group: (string) ($groups->get($source->groups_id ?? 0) ?? $release->group_name ?? ''),
                poster: (string) ($source->fromname ?? ''),
                renamed: (int) ($source->isrenamed ?? 0) === 1,
                pp_done: $postProcessed->has($release->id),
                entity: $entities[(int) $release->id] ?? null,
                in_basket: in_array((int) $release->id, $basket, true),
                watched: isset($entities[(int) $release->id]) && match ($entities[(int) $release->id]->root) {
                    'movies' => in_array($entities[(int) $release->id]->id, $watchedMovies, true),
                    'tv' => in_array($entities[(int) $release->id]->id, $watchedShows, true),
                    default => false,
                },
                reports: (int) ($release->total_report_count ?? 0),
                public_responses: (int) ($release->report_response_count ?? 0),
            );
            if ($release instanceof Model) {
                $release->setAttribute('row_data', $row);
            } else {
                $release->row_data = $row;
            }
        }
    }

    /**
     * Filter finished releases in SQL and in the rows already loaded for display.
     *
     * @template T of Builder|Collection<array-key, \stdClass>
     *
     * @param  T  $releases
     * @return T
     */
    public function postProcessed(Builder|Collection $releases, string $prefix = ''): Builder|Collection
    {
        return $releases->whereNull($prefix.'additional_pp_claim_token')
            ->whereNotNull($prefix.'passwordstatus')->where($prefix.'passwordstatus', '>=', 0)
            ->whereNotNull($prefix.'nfostatus')
            ->whereNotBetween($prefix.'nfostatus', [NfoService::NFO_FAILED + 1, NfoService::NFO_NONFO - 1]);
    }
}
