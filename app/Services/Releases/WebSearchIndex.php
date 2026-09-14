<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\WebSearchState;
use App\Models\User;
use App\Services\Search\Contracts\SearchServiceInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class WebSearchIndex
{
    public function __construct(private readonly SearchServiceInterface $search, private readonly ReleaseBrowseService $releases) {}

    /** @return list<int>|null Null means the index could not complete the search. */
    public function releases(WebSearchState $state, User $user, bool $fuzzy = false): ?array
    {
        $text = $state->terms->freeText();
        if ($text === '' || ($fuzzy && (! $this->search->isFuzzyEnabled() || preg_match('/(?:^|[\s(])[-!]/u', $text)))) {
            return [];
        }
        $parameters = array_intersect_key($state->parameters, array_flip(['t', 'cat', 'group', 'minage', 'maxage', 'minsize', 'maxsize', 'minc']));
        ksort($parameters);
        $criteria = [
            'phrases' => ['searchname' => $text], 'excluded_category_ids' => (array) $user->categoryexclusions,
            'password_allow_rar' => $this->releases->passwordAllowRar(), 'try_fuzzy' => false,
            'sort_field' => 'id', 'sort_dir' => 'asc', 'web_force_fuzzy' => $fuzzy,
        ];
        $cacheKey = 'web-search:ids:'.hash('sha256', serialize([
            $parameters, $criteria, $user->id, config('search.default'), config('search.index_generation', '1'),
            Cache::get('releases:cache_version', 1),
        ]));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        if (isset($parameters['t']) || isset($parameters['cat'])) {
            $categories = DB::table('categories');
            if (isset($parameters['t'])) {
                $scope = array_map('intval', explode(',', $parameters['t']));
                $categories->where(fn ($query) => $query->whereIn('id', $scope)->orWhereIn('root_categories_id', $scope));
            }
            if (isset($parameters['cat'])) {
                $categories->where('id', (int) $parameters['cat']);
            }
            $criteria['category_ids'] = $categories->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            if ($criteria['category_ids'] === []) {
                return [];
            }
        }
        if (isset($parameters['group'])) {
            $criteria['groups_id'] = (int) DB::table('usenet_groups')->where('name', $parameters['group'])->value('id');
            if ($criteria['groups_id'] === 0) {
                return [];
            }
        }
        foreach (['minage' => 'max_date', 'maxage' => 'min_date'] as $parameter => $criterion) {
            if (isset($parameters[$parameter])) {
                $criteria[$criterion] = now()->subDays((int) $parameters[$parameter])->timestamp;
            }
        }
        foreach (['minsize' => 'min_size', 'maxsize' => 'max_size'] as $parameter => $criterion) {
            if (isset($parameters[$parameter])) {
                $criteria[$criterion] = (int) round((float) $parameters[$parameter] * 1024 * 1024);
            }
        }
        if (isset($parameters['minc'])) {
            $criteria['min_completion'] = (int) $parameters['minc'];
        }
        $ids = [];
        $afterId = 0;
        do {
            $page = $this->search->searchReleasesFiltered([...$criteria, 'web_after_id' => $afterId], 500, 0);
            if (($page['available'] ?? true) === false) {
                return null;
            }
            $batch = array_map('intval', $page['ids']);
            if ($batch === []) {
                break;
            }
            $next = max($batch);
            if ($next <= $afterId) {
                return null;
            }
            array_push($ids, ...$batch);
            $afterId = $next;
        } while (($page['has_more'] ?? count($batch) === 500) === true);
        $ids = array_values(array_unique($ids));
        Cache::put($cacheKey, $ids, 30);

        return $ids;
    }

    /**
     * @param  array<string, string>  $fields
     * @return list<int>
     */
    public function movies(array $fields): array
    {
        if ($fields === []) {
            return [];
        }
        $ids = [];
        $afterId = 0;
        while (true) {
            $page = $this->search->searchMoviesByFields($fields, 500, $afterId);
            $batch = array_map('intval', $page['movieinfo_ids']);
            if ($batch === []) {
                break;
            }
            $next = max($batch);
            if ($next <= $afterId) {
                return [];
            }
            array_push($ids, ...$batch);
            $afterId = $next;
        }

        return array_values(array_unique($ids));
    }
}
