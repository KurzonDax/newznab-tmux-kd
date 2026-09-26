<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\ReleaseBrowserState;
use App\Enums\BrowseRoot;
use App\Models\Category;
use App\Services\PosterIdentityBrowserContext;
use App\Services\Releases\ReleaseBrowserQuery;
use App\Services\Releases\ReleaseCoverBrowser;
use Illuminate\Http\Request;

class BrowseController extends BasePageController
{
    /**
     * @throws \Exception
     */
    public function index(Request $request): mixed
    {
        return $this->renderBrowser($request, BrowseRoot::All);
    }

    public function show(Request $request, string $parentCategory, string $id = 'All'): mixed
    {
        $root = BrowseRoot::fromRoute($parentCategory);
        abort_if($root === null || $root === BrowseRoot::All, 404);
        $category = null;
        if (strtolower($id) !== 'all') {
            $query = Category::query()->where('root_categories_id', $root->categoryId());
            $category = ctype_digit($id)
                ? $query->whereKey((int) $id)->firstOrFail()
                : $query->where('title', $id)->firstOrFail();
            abort_if(in_array((int) $category->id, (array) $this->userdata->categoryexclusions), 403);
        }
        if ($root === BrowseRoot::Tv) {
            return redirect()->route('tv.releases', $category === null ? [] : ['category' => [(int) $category->id]]);
        }

        return $this->renderBrowser($request, $root, $category);
    }

    private function renderBrowser(Request $request, BrowseRoot $root, ?Category $category = null): mixed
    {
        $state = ReleaseBrowserState::fromRequest($request, $root, $this->userdata, $category?->id);
        if ($state->letter !== '' && ! $request->has('page') && ! $request->has('_fragment')) {
            $page = app(ReleaseCoverBrowser::class)->letterPage($state, $this->userdata);

            return redirect()->to($request->url().'?'.http_build_query([...$state->queryParameters($request), 'sort' => 'title', 'page' => $page], '', '&', PHP_QUERY_RFC3986));
        }
        if ($request->input('_fragment') === 'cover') {
            $id = $request->input('cover');
            abort_unless(is_string($id), 404);
            $rows = app(ReleaseCoverBrowser::class)->expanded($state, $this->userdata, $id, $request->integer('release_page', 1), $request->integer('release_per', 24));

            return view('components.release-browser.expanded-cover', ['rows' => $rows, 'state' => $state]);
        }
        $browserQuery = app(ReleaseBrowserQuery::class);
        $results = $state->view === 'covers'
            ? app(ReleaseCoverBrowser::class)->paginate($state, $this->userdata)
            : $browserQuery->paginate($state, $this->userdata);
        if ($state->page > $results->lastPage()) {
            return redirect()->to($state->pageUrl($request, $results->lastPage()));
        }
        $title = $category === null ? $root->label() : $root->label().' · '.$category->title;
        if ($root === BrowseRoot::Movies && $state->view === 'covers' && $state->trending) {
            $title = 'Trending '.$root->label();
        }
        if ($state->watching && $root === BrowseRoot::Movies) {
            $title = $root->label().' you follow';
        }
        if ($state->group !== '') {
            $title = 'Releases in '.$state->group;
        }
        if ($state->posterIdentity !== '') {
            $title = 'Posts by '.$state->posterIdentity;
        }

        $data = array_merge($this->viewData, [
            'category' => $category->id ?? $root->categoryId() ?? -1,
            'catname' => $category->title ?? $root->label(),
            'results' => $results, 'lastvisit' => $this->userdata->lastlogin,
            'browserState' => $state, 'browserTitle' => $title, 'meta_title' => $title,
            'filterOptions' => $browserQuery->filterOptions($state, $this->userdata),
            'sortOptions' => $browserQuery->sortOptions($state),
        ]);
        if ($state->posterIdentity !== '') {
            $context = app(PosterIdentityBrowserContext::class)->forIdentity($state->posterIdentity, $this->userdata, $request->session()->get('poster_identity_blacklist_sweep_started') === true);

            return view('poster-identity.index', array_merge($data, $context));
        }

        return view('browse.index', $data);
    }

    public function group(Request $request): mixed
    {
        $group = $request->input('g');
        if (! is_string($group) || $group === '') {
            return redirect()->back()->with('error', 'Group parameter is required');
        }

        return redirect()->route('browse.all', [...$request->except('g'), 'group' => $group]);
    }
}
