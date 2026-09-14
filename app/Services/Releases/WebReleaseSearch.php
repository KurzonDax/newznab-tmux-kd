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
        $fields = $search->terms->indexTerms();
        unset($fields['all']);
        if ($fields !== []) {
            if (! Schema::hasTable('movieinfo')) {
                $query->whereRaw('1=0');
            } else {
                $movies = DB::table('movieinfo as search_entity')->select('imdbid');
                $movieIds = $this->index->movies($fields);
                if ($movieIds !== []) {
                    $movies->whereIntegerInRaw('search_entity.id', $movieIds);
                } else {
                    foreach ($fields as $field => $text) {
                        WebSearchText::apply($movies, ['search_entity.'.$field], $text);
                    }
                }
                $query->whereIn('r.imdbid', $movies)
                    ->whereIn('r.categories_id', DB::table('categories')->select('id')->where('root_categories_id', BrowseRoot::Movies->categoryId()));
            }
        }
        $base = clone $query;
        $text = $search->terms->freeText();
        if ($text !== '') {
            $releaseIds = $this->index->releases($search, $user);
            $this->applyText($query, $text, $releaseIds);
        }
        $total = $query->count();
        if ($total === 0 && $text !== '') {
            $fuzzyIds = $this->index->releases($search, $user, fuzzy: true);
            if ($fuzzyIds !== null && $fuzzyIds !== []) {
                $query = $base->whereIntegerInRaw('r.id', $fuzzyIds);
                $total = $query->count();
            }
        }
        $page = min($state->page, max(1, (int) ceil($total / $state->per)));
        [$column, $direction] = ReleaseSort::resolve($state->sort)->order();
        $query->orderByRaw($column.' '.$direction);
        $rows = $query->orderByDesc('r.id')->offset(($page - 1) * $state->per)->limit($state->per)->get(['r.*']);
        $this->releases->loadReleaseRows($rows);

        return new ReleaseBrowserPage($rows, $total, $state->per, $page, ['path' => route('search'), 'query' => $search->parameters]);
    }

    /** @param list<int>|null $releaseIds */
    private function applyText(Builder $query, string $text, ?array $releaseIds): void
    {
        $entities = [];
        foreach ([BrowseRoot::Movies, BrowseRoot::Tv, BrowseRoot::Audio, BrowseRoot::Console, BrowseRoot::Games, BrowseRoot::Books] as $root) {
            $source = $this->titles->source($root);
            if (! Schema::hasTable($source['table'])) {
                continue;
            }
            $entity = DB::table($source['table'].' as search_entity')->select($source['key']);
            if ($root === BrowseRoot::Movies && ! preg_match('/[|()]|(?:^|\s)[-!]/u', $text)) {
                $movieIds = $this->index->movies(['title' => $text]);
                if ($movieIds !== []) {
                    $entity->whereIntegerInRaw('search_entity.id', $movieIds);
                }
            }
            WebSearchText::apply($entity, $root === BrowseRoot::Audio ? ['search_entity.title', 'search_entity.artist'] : ['search_entity.title'], $text, ["COALESCE(NULLIF(TRIM(r.display_name), ''), r.searchname)"]);
            $entities[] = [$root, $source['releaseKey'], $entity];
        }
        if (Schema::hasTable('anidb_info') && Schema::hasTable('anidb_titles')) {
            $anime = DB::table('anidb_titles as search_entity')->select('anidbid')->whereIn('anidbid', DB::table('anidb_info')->select('anidbid'));
            WebSearchText::apply($anime, ['search_entity.title'], $text, ["COALESCE(NULLIF(TRIM(r.display_name), ''), r.searchname)"]);
            $entities[] = [BrowseRoot::Tv, 'anidbid', $anime];
        }
        $query->where(function (Builder $matches) use ($text, $releaseIds, $entities): void {
            $matches->whereIntegerInRaw('r.id', $releaseIds ?? []);
            $matches->orWhere(function (Builder $names) use ($text): void {
                $names->whereNotNull('r.display_name')->where('r.display_name', '<>', '');
                WebSearchText::apply($names, ['r.display_name'], $text);
            });
            if (($releaseIds === null || $releaseIds === []) && config('nntmux.mysql_search_fallback', false) === true) {
                $matches->orWhere(function (Builder $names) use ($text): void {
                    WebSearchText::apply($names, ["COALESCE(NULLIF(TRIM(r.display_name), ''), r.searchname)"], $text);
                });
            }
            foreach ($entities as [$root, $releaseKey, $entity]) {
                $matches->orWhere(function (Builder $matched) use ($root, $releaseKey, $entity): void {
                    $matched->whereIn('r.categories_id', DB::table('categories')->select('id')->where('root_categories_id', $root->categoryId()))
                        ->whereIn('r.'.$releaseKey, $entity);
                });
            }
        });
    }
}
