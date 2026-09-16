<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseBrowserState;
use App\Data\WebSearchState;
use App\Enums\BrowseRoot;
use App\Enums\ReleaseSort;
use App\Models\User;
use App\Support\ReleaseBrowserPage;
use App\Support\WebSearchText;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WebReleaseSearch
{
    public function __construct(private readonly ReleaseBrowserQuery $browser, private readonly ReleaseBrowseService $releases, private readonly TitleMetadataLoader $titles, private readonly WebSearchIndex $index) {}

    public function paginate(WebSearchState $search, User $user): ReleaseBrowserPage
    {
        $state = $search->browser;
        $entityFilters = $this->index->entityFilters($search);
        $result = $this->index->page($search, $user, $entityFilters);
        if (($result['available'] ?? true) || ! config('nntmux.mysql_search_fallback', false)) {
            $ids = array_slice($result['ids'], 0, $state->per);
            $rows = $ids === [] ? collect() : DB::table('releases')->whereIn('id', $ids)->get()->keyBy('id');
            $rows = collect($ids)->map(fn (int $id) => $rows->get($id))->filter()->values();
            $this->releases->loadReleaseRows($rows);

            return new ReleaseBrowserPage($rows, $result['total'], $state->per, $result['page'] ?? 1,
                ['path' => route('search'), 'query' => $search->parameters],
                available: $result['available'] ?? true, reachableTotal: $result['maximum'] ?? null);
        }

        $query = $this->browser->matchingQuery(ReleaseBrowserState::fromRequest(new Request(array_intersect_key($search->parameters, array_flip(['minc', 'group']))), BrowseRoot::All, $user, tableOnly: true), $user);
        if (isset($search->parameters['t'])) {
            $ids = array_map('intval', array_filter(explode(',', $search->parameters['t']), ctype_digit(...)));
            $query->whereIn('r.categories_id', DB::table('categories')->select('id')->whereIn('id', $ids)->orWhereIn('root_categories_id', $ids));
        }
        if (isset($search->parameters['cat'])) {
            $query->where('r.categories_id', (int) $search->parameters['cat']);
        }
        if (isset($search->parameters['poster'])) {
            $query->where('r.fromname', $search->parameters['poster']);
        }
        foreach (['minage' => '<=', 'maxage' => '>='] as $key => $operator) {
            if (isset($search->parameters[$key])) {
                $query->where('r.postdate', $operator, now()->subDays((int) $search->parameters[$key]));
            }
        }
        foreach (['minsize' => '>=', 'maxsize' => '<='] as $key => $operator) {
            if (isset($search->parameters[$key])) {
                $query->where('r.size', $operator, (int) round((float) $search->parameters[$key] * 1024 * 1024));
            }
        }
        foreach ($entityFilters as $attribute => $keys) {
            $query->whereIn('r.'.$attribute, $keys);
        }
        $text = $search->terms->freeText();
        if ($text !== '') {
            $this->applyText($query, $text);
        }
        $total = $query->count();
        $page = min($state->page, max(1, (int) ceil($total / $state->per)));
        [$column, $direction] = ReleaseSort::resolve($state->sort)->order();
        $query->orderByRaw($column.' '.$direction);
        $rows = $query->orderByDesc('r.id')->offset(($page - 1) * $state->per)->limit($state->per)->get(['r.*']);
        $this->releases->loadReleaseRows($rows);

        return new ReleaseBrowserPage($rows, $total, $state->per, $page, ['path' => route('search'), 'query' => $search->parameters]);
    }

    private function applyText(Builder $query, string $text): void
    {
        $entities = [];
        foreach ([BrowseRoot::Movies, BrowseRoot::Tv, BrowseRoot::Audio, BrowseRoot::Console, BrowseRoot::Games, BrowseRoot::Books] as $root) {
            $source = $this->titles->source($root);
            if (! Schema::hasTable($source['table'])) {
                continue;
            }
            $entity = DB::table($source['table'].' as search_entity')->select($source['key']);
            WebSearchText::apply($entity, $root === BrowseRoot::Audio ? ['search_entity.title', 'search_entity.artist'] : ['search_entity.title'], $text, ["COALESCE(NULLIF(TRIM(r.display_name), ''), r.searchname)"]);
            $entities[] = [$root, $source['releaseKey'], $entity];
        }
        if (Schema::hasTable('anidb_info') && Schema::hasTable('anidb_titles')) {
            $anime = DB::table('anidb_titles as search_entity')->select('anidbid')->whereIn('anidbid', DB::table('anidb_info')->select('anidbid'));
            WebSearchText::apply($anime, ['search_entity.title'], $text, ["COALESCE(NULLIF(TRIM(r.display_name), ''), r.searchname)"]);
            $entities[] = [BrowseRoot::Tv, 'anidbid', $anime];
        }
        $query->where(function (Builder $matches) use ($text, $entities): void {
            $matches->where(function (Builder $names) use ($text): void {
                WebSearchText::apply($names, ["COALESCE(NULLIF(TRIM(r.display_name), ''), r.searchname)"], $text);
            });
            foreach ($entities as [$root, $releaseKey, $entity]) {
                $matches->orWhere(function (Builder $matched) use ($root, $releaseKey, $entity): void {
                    $matched->whereIn('r.categories_id', DB::table('categories')->select('id')->where('root_categories_id', $root->categoryId()))
                        ->whereIn('r.'.$releaseKey, $entity);
                });
            }
        });
    }
}
