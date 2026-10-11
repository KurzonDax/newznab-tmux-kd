<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseBrowserState;
use App\Data\ReleaseListFilters;
use App\Data\WebSearchPage;
use App\Data\WebSearchState;
use App\Enums\BrowseRoot;
use App\Enums\ReleaseSort;
use App\Models\User;
use App\Support\WebSearchText;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which releases a search page holds (issue #1050): the search index answers, at the generic
 * lists' page size and in the list's sort; when it cannot and the database fallback is on
 * (`nntmux.mysql_search_fallback`), the database answers with its own count and pages; a fallback
 * that fails leaves the search unavailable. The rows themselves are built by GenericReleaseRows
 * from the ids.
 */
final class WebReleaseSearch
{
    public function __construct(private readonly ReleaseBrowserQuery $browser, private readonly TitleMetadataLoader $titles, private readonly WebSearchIndex $index) {}

    public function paginate(WebSearchState $search, User $user, ReleaseSort $sort): WebSearchPage
    {
        $per = ReleaseListFilters::PER_PAGE;
        $entityFilters = $this->index->entityFilters($search);
        $result = $this->index->page($search, $user, $entityFilters, $sort);
        $available = (bool) ($result['available'] ?? true);
        if ($available || ! config('nntmux.mysql_search_fallback', false)) {
            $total = (int) $result['total'];
            $maximum = $result['maximum'] ?? null;

            return new WebSearchPage(
                ids: array_values(array_map('intval', array_slice($result['ids'], 0, $per))),
                total: $total,
                page: (int) ($result['page'] ?? 1),
                lastPage: max(1, (int) ceil(($maximum === null ? $total : min($total, (int) $maximum)) / $per)),
                available: $available,
                beyondReach: $available && $maximum !== null && $total > (int) $maximum,
            );
        }

        try {
            return $this->fromDatabase($search, $user, $sort, $entityFilters);
        } catch (QueryException $exception) {
            // An unsuccessful fallback leaves the search unavailable, as a fallback that is switched off does.
            report($exception);

            return new WebSearchPage([], 0, available: false);
        }
    }

    /**
     * The database fallback: the search's own conditions on `releases`, counted and paged by the database.
     *
     * @param  array<string, list<int|string>>  $entityFilters
     */
    private function fromDatabase(WebSearchState $search, User $user, ReleaseSort $sort, array $entityFilters): WebSearchPage
    {
        $per = ReleaseListFilters::PER_PAGE;
        $threshold = $search->threshold();
        $base = array_filter(['minc' => $threshold === null ? null : (string) $threshold, 'group' => $search->parameters['group'] ?? null], is_string(...));
        $query = $this->browser->matchingQuery(ReleaseBrowserState::fromRequest(new Request($base), BrowseRoot::All, $user, tableOnly: true), $user);
        if ($search->importedOnly()) {
            $query->where('r.is_imported', 1);
        }
        if (isset($search->parameters['t'])) {
            $ids = $search->scopeIds();
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
        $lastPage = max(1, (int) ceil($total / $per));
        $page = min($search->page, $lastPage);
        [$column, $direction] = $sort->order();
        $query->orderByRaw($column.' '.$direction);
        $ids = $query->orderByDesc('r.id')->offset(($page - 1) * $per)->limit($per)->pluck('r.id')->map(static fn (mixed $id): int => (int) $id)->all();

        return new WebSearchPage($ids, $total, $page, $lastPage);
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
