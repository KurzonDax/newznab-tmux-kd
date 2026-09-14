<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseBrowserState;
use App\Enums\BrowseRoot;
use App\Models\User;
use App\Support\ReleaseBrowserPage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ReleaseBrowserQuery
{
    public function __construct(private readonly ReleaseBrowseService $releases, private readonly ReleaseBrowserMetadata $metadata) {}

    public function paginate(ReleaseBrowserState $state, User $user): ReleaseBrowserPage
    {
        $query = $this->baseQuery($state, $user);
        $this->metadata->filter($query, $state->root, $state->filters);
        $displayName = $this->displayName();
        $totalBeforeEligibility = $query->count();
        $total = $totalBeforeEligibility;
        if ($state->view === 'cards') {
            app(ReleaseRowDataLoader::class)->postProcessed($query, 'r.');
            $query->where('r.isrenamed', 1);
            $total = $query->count();
        }
        $page = min($state->page, max(1, (int) ceil($total / $state->per)));
        if ($state->sort === 'title') {
            $query->orderByRaw($displayName.' ASC');
        } elseif (isset($this->metadata->sorts($state->root)[$state->sort])) {
            $column = $this->metadata->fields($state->root)[$state->sort] ?? 'r.adddate';
            $query->orderByRaw($column.($state->sort === 'artist' ? ' ASC' : ' DESC'));
        } else {
            $query->orderByDesc('r.adddate');
        }
        $rows = $query->orderByDesc('r.id')
            ->offset(($page - 1) * $state->per)->limit($state->per)->get(['r.*']);
        if ($rows->isNotEmpty() && Schema::hasTable('release_reports')) {
            $reports = DB::table('release_reports')->whereIn('releases_id', $rows->pluck('id'))
                ->select('releases_id')->selectRaw('COUNT(*) AS reports')
                ->selectRaw("SUM(CASE WHEN response_is_public = 1 AND response IS NOT NULL AND response <> '' THEN 1 ELSE 0 END) AS public_responses")
                ->groupBy('releases_id')->get()->keyBy('releases_id');
            foreach ($rows as $release) {
                $release->total_report_count = (int) ($reports->get($release->id)->reports ?? 0);
                $release->report_response_count = (int) ($reports->get($release->id)->public_responses ?? 0);
            }
        }
        $this->releases->loadReleaseRows($rows);

        return new ReleaseBrowserPage($rows, $total, $state->per, $page, [
            'path' => request()->url(), 'query' => $state->queryParameters(request()),
        ], hiddenCount: $totalBeforeEligibility - $total);
    }

    /** @return array<string, list<string>> */
    public function filterOptions(ReleaseBrowserState $state, User $user): array
    {
        return $this->metadata->options($this->baseQuery($state, $user), $state->root);
    }

    /** @return array<string, string> */
    public function sortOptions(ReleaseBrowserState $state): array
    {
        return $this->metadata->sorts($state->root);
    }

    private function baseQuery(ReleaseBrowserState $state, User $user): Builder
    {
        $query = DB::table('releases as r')
            ->whereRaw('r.passwordstatus '.$this->releases->showPasswords())
            ->whereNotIn('r.categories_id', (array) $user->categoryexclusions);
        if ($state->basketOnly) {
            $query->whereIn('r.id', DB::table('users_releases')->select('releases_id')->where('users_id', $user->id));
        }
        if ($state->minCompletion > 0) {
            $query->where('r.completion', '>=', $state->minCompletion);
        }
        if ($state->categoryId !== null) {
            $query->where('r.categories_id', $state->categoryId);
        } elseif ($state->root->categoryId() !== null) {
            $query->whereIn('r.categories_id', DB::table('categories')->select('id')->where('root_categories_id', $state->root->categoryId()));
        }
        if ($state->group !== '') {
            $query->whereIn('r.groups_id', DB::table('usenet_groups')->select('id')->where('name', $state->group));
        }
        if ($state->posterIdentity !== '') {
            $query->where('r.fromname', $state->posterIdentity);
            if (DB::getDriverName() !== 'sqlite') {
                $query->whereRaw('BINARY r.fromname = BINARY ?', [$state->posterIdentity]);
            }
        }
        if ($state->watching) {
            $query->where(function (Builder $watched) use ($state, $user): void {
                foreach ([BrowseRoot::Movies, BrowseRoot::Tv] as $root) {
                    if ($state->root !== BrowseRoot::All && $state->root !== $root) {
                        continue;
                    }
                    $watched->orWhere(function (Builder $titles) use ($root, $user): void {
                        $movies = $root === BrowseRoot::Movies;
                        $key = $movies ? 'imdbid' : 'videos_id';
                        $table = $movies ? 'user_movies' : 'user_series';
                        $subscription = DB::table($table.' as watched')->selectRaw('1')
                            ->where('watched.users_id', $user->id)->whereColumn('watched.'.$key, 'r.'.$key);
                        if (Schema::hasColumn($table, 'categories')) {
                            $subscription->where(function (Builder $categories): void {
                                $membership = DB::getDriverName() === 'sqlite'
                                    ? "INSTR('|' || watched.categories || '|', '|' || r.categories_id || '|') > 0"
                                    : "LOCATE(CONCAT('|', r.categories_id, '|'), CONCAT('|', watched.categories, '|')) > 0";
                                $categories->whereNull('watched.categories')->orWhere('watched.categories', '')->orWhereRaw($membership);
                            });
                        }
                        $titles->whereIn('r.categories_id', DB::table('categories')->select('id')->where('root_categories_id', $root->categoryId()))
                            ->whereExists($subscription);
                    });
                }
            });
        }
        $displayName = $this->displayName();
        if ($state->query !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $state->query).'%';
            $query->whereRaw($displayName." LIKE ? ESCAPE '!'", [$pattern]);
        }
        $this->metadata->join($query, $state->root);

        return $query;
    }

    private function displayName(): string
    {
        return "COALESCE(NULLIF(TRIM(r.display_name), ''), r.searchname)";
    }
}
