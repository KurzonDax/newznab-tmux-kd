<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BrowseRoot;
use App\Services\Releases\WatchlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WatchlistController extends BasePageController
{
    public function index(Request $request, WatchlistService $watchlist): mixed
    {
        $tab = $request->input('tab', $this->userdata->getDirectPermissions()->contains('name', 'view movies') ? 'movies' : 'tv');
        [$root] = $this->identity(is_string($tab) ? $tab : 'movies', '1');
        $find = is_string($request->input('q')) ? mb_substr(trim($request->input('q')), 0, 200) : '';
        $titles = $watchlist->titles($root, $this->userdata)->paginate(48)->withQueryString();
        $titles->setCollection($watchlist->present($titles->getCollection(), $root, $this->userdata, true));
        $found = $find === '' ? collect() : $watchlist->present($watchlist->titles($root, $this->userdata, $find)->limit(6)->get(), $root, $this->userdata, false);
        $data = [...$this->viewData, 'userdata' => $this->userdata, 'root' => $root, 'titles' => $titles, 'found' => $found, 'find' => $find,
            'counts' => $watchlist->counts($this->userdata), 'meta_title' => 'Watchlist'];

        return view($request->input('_fragment') === 'lists' ? 'watchlist.lists' : 'watchlist.index', $data);
    }

    public function picker(Request $request, string $root, string $id, WatchlistService $watchlist): JsonResponse
    {
        [$category, $id] = $this->identity($root, $id);

        return response()->json($watchlist->picker($category, $id, $this->userdata, $request->session()->get('watchlist.categories.'.$this->userdata->id)));
    }

    public function save(Request $request, string $root, string $id, WatchlistService $watchlist): JsonResponse
    {
        [$category, $id] = $this->identity($root, $id);
        $watchlist->picker($category, $id, $this->userdata);
        if ($request->has('undo_token')) {
            $validated = $request->validate(['undo_token' => ['required', 'string', 'max:20000']]);
            $watchlist->restore($category, $id, $this->userdata, $validated['undo_token']);

            return response()->json($watchlist->picker($category, $id, $this->userdata));
        }
        $validated = $request->validate([
            'categories' => ['required', 'array', 'min:1', 'max:100'],
            'categories.*' => ['required', 'integer', 'distinct', Rule::in(array_keys($watchlist->categories($category, $this->userdata)))],
        ]);
        $categories = array_map('intval', $validated['categories']);
        $watchlist->save($category, $id, $this->userdata, $categories);
        $labels = array_values(array_intersect_key($watchlist->categories($category, $this->userdata), array_flip($categories)));
        $request->session()->put('watchlist.categories.'.$this->userdata->id, array_map(mb_strtoupper(...), $labels));

        return response()->json($watchlist->picker($category, $id, $this->userdata));
    }

    public function remove(Request $request, string $root, string $id, WatchlistService $watchlist): JsonResponse
    {
        [$category, $id] = $this->identity($root, $id);
        $data = $watchlist->picker($category, $id, $this->userdata);
        $removed = $watchlist->remove($category, $id, $this->userdata);

        return response()->json([...$data, 'watched' => false, 'removedCategories' => $removed['categories'], 'undoToken' => $removed['undoToken'], 'counts' => $watchlist->counts($this->userdata)]);
    }

    /** @return array{BrowseRoot, string} */
    private function identity(string $root, string $id): array
    {
        $category = BrowseRoot::tryFrom($root);
        abort_unless(in_array($category, [BrowseRoot::Movies, BrowseRoot::Tv], true), 404);
        abort_unless($this->userdata->getDirectPermissions()->contains('name', 'view '.$category->value), 403);
        $id = $category === BrowseRoot::Movies ? preg_replace('/^tt/i', '', $id) : $id;
        abort_unless(is_string($id) && ctype_digit($id) && (int) $id > 0, 404);

        return [$category, $category === BrowseRoot::Tv ? (string) (int) $id : $id];
    }
}
