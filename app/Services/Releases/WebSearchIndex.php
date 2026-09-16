<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\WebSearchState;
use App\Enums\ReleaseSort;
use App\Models\User;
use App\Services\Search\Contracts\SearchServiceInterface;
use App\Support\WebSearchFields;
use App\Support\WebSearchText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WebSearchIndex
{
    public function __construct(private readonly SearchServiceInterface $search, private readonly ReleaseBrowseService $releases) {}

    /**
     * @param  array<string, list<int|string>>  $entityFilters
     * @return array<string, mixed>
     */
    public function page(WebSearchState $state, User $user, array $entityFilters = []): array
    {
        $text = $state->terms->freeText();
        $parameters = $state->parameters;
        [$column, $direction] = ReleaseSort::resolve($state->browser->sort)->order();
        $sort = match ($column) {
            'r.postdate' => 'postdate_ts', 'r.adddate' => 'adddate_ts', 'r.grabs' => 'grabs', default => 'sort_name',
        };
        $criteria = [
            'phrases' => $text === '' ? null : ['searchname' => $text],
            'web_search' => true, 'entity_filters' => $entityFilters,
            'excluded_category_ids' => (array) $user->categoryexclusions,
            'password_allow_rar' => $this->releases->passwordAllowRar(), 'try_fuzzy' => false,
            'sort_field' => $sort, 'sort_dir' => $direction, 'web_force_fuzzy' => false,
        ];
        if (in_array([], $entityFilters, true)) {
            return ['ids' => [], 'total' => 0, 'available' => true];
        }
        if (isset($parameters['poster'])) {
            $criteria['poster'] = $parameters['poster'];
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
                return ['ids' => [], 'total' => 0, 'available' => true];
            }
        }
        if (isset($parameters['group'])) {
            $criteria['groups_id'] = (int) DB::table('usenet_groups')->where('name', $parameters['group'])->value('id');
            if ($criteria['groups_id'] === 0) {
                return ['ids' => [], 'total' => 0, 'available' => true];
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
        $maximum = config('search.default') === 'elasticsearch' ? 10000 : max(1, (int) config('search.drivers.manticore.max_matches', 10000));
        $per = $state->browser->per;
        $page = min($state->browser->page, (int) ceil($maximum / $per));
        $offset = ($page - 1) * $per;
        $limit = min($per, $maximum - $offset);
        $result = $this->search->searchReleasesFiltered($criteria, $limit, $offset);
        if (($result['available'] ?? true) && $result['total'] === 0 && $text !== '' && $this->search->isFuzzyEnabled() && ! preg_match('/(?:^|[\s(])[-!]/u', implode(' ', $state->terms->indexTerms()))) {
            $result = $this->search->searchReleasesFiltered([...$criteria, 'web_force_fuzzy' => true], $limit, $offset);
        }

        return [...$result, 'page' => $page, 'maximum' => $maximum];
    }

    /** @return array<string, list<int|string>> */
    public function entityFilters(WebSearchState $state): array
    {
        $groups = [];
        $definitions = app(WebSearchFields::class)->all();
        foreach ($state->terms->indexTerms() as $prefix => $value) {
            if ($prefix === 'all') {
                continue;
            }
            $definition = $definitions[$prefix];
            $group = $definition['attribute'];
            $groups[$group]['definition'] = $definition;
            $groups[$group]['fields'][$definition['field']] = $value;
        }
        $filters = [];
        foreach ($groups as $attribute => $group) {
            $definition = $group['definition'];
            $keys = [];
            $after = 0;
            do {
                $result = $this->search->searchEntityFields($definition['index'], $group['fields'], $definition['key'], 500, $after);
                if (! $result['available']) {
                    $keys = [];
                    break;
                }
                array_push($keys, ...$result['keys']);
                $next = $result['ids'] === [] ? $after : max($result['ids']);
                if ($next <= $after) {
                    break;
                }
                $after = $next;
            } while ($result['has_more']);
            if ($keys === [] && Schema::hasTable($definition['table'])) {
                $query = DB::table($definition['table']);
                foreach ($group['fields'] as $field => $text) {
                    WebSearchText::apply($query, [$field], $text);
                }
                $keys = $query->pluck($definition['key'])->all();
            }
            $filters[$attribute] = array_values(array_unique($keys));
        }

        return $filters;
    }
}
