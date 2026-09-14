<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\ReleaseBrowserState;
use App\Enums\BrowseRoot;
use App\Models\Release;
use App\Models\UsersRelease;
use App\Services\Releases\ReleaseBrowserQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CartController extends BasePageController
{
    /**
     * @throws \Exception
     */
    public function index(Request $request): mixed
    {
        $state = ReleaseBrowserState::fromRequest($request, BrowseRoot::All, $this->userdata, basketOnly: true);
        $results = app(ReleaseBrowserQuery::class)->paginate($state, $this->userdata);
        if ($state->page > $results->lastPage()) {
            return redirect()->to($state->pageUrl($request, $results->lastPage()));
        }

        return view('cart.index', array_merge($this->viewData, [
            'results' => $results, 'browserState' => $state, 'meta_title' => 'Download Basket',
        ]));
    }

    /**
     * @throws \Exception
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $guids = collect(explode(',', $this->scalarInput($request, 'id')))
            ->map(static fn (string $guid): string => trim($guid))
            ->filter()
            ->unique()
            ->values();

        $releaseIds = Release::query()
            ->whereIn('guid', $guids)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($releaseIds === []) {
            return $request->ajax() || $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'No releases found'], 404)
                : redirect()->to('/cart/index');
        }

        $existingReleaseIds = UsersRelease::query()
            ->where('users_id', $this->userdata->id)
            ->whereIn('releases_id', $releaseIds)
            ->pluck('releases_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $missingReleaseIds = array_values(array_diff($releaseIds, $existingReleaseIds));
        $addedCount = UsersRelease::addCartForReleases((int) $this->userdata->id, $missingReleaseIds);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $addedCount > 0 ? "{$addedCount} item(s) added to cart" : 'Items already in cart',
                'cartCount' => UsersRelease::where('users_id', $this->userdata->id)->count(),
            ]);
        }

        return redirect()->to('/cart/index');
    }

    /**
     * @param  array<string, mixed>  $id
     *
     * @throws \Exception
     */
    public function destroy(Request $request, array|string $id): RedirectResponse|JsonResponse
    {
        $guids = is_array($id) ? $id : explode(',', $id);
        UsersRelease::delCartByGuid($guids, $this->userdata->id);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'cartCount' => UsersRelease::where('users_id', $this->userdata->id)->count(),
            ]);
        }

        return redirect()->to('/cart/index');
    }
}
