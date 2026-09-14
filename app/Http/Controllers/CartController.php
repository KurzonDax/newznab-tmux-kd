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
use Illuminate\Support\Facades\Cache;
use STS\ZipStream\Builder;
use Symfony\Component\HttpFoundation\Response;

class CartController extends BasePageController
{
    /**
     * @throws \Exception
     */
    public function index(Request $request): mixed
    {
        $state = $this->basketState($request);
        $results = app(ReleaseBrowserQuery::class)->paginate($state, $this->userdata);
        if ($state->page > $results->lastPage()) {
            return redirect()->to($state->pageUrl($request, $results->lastPage()));
        }

        return view('cart.index', array_merge($this->viewData, [
            'results' => $results, 'browserState' => $state, 'meta_title' => 'Basket',
        ]));
    }

    public function legacyIndex(Request $request): RedirectResponse
    {
        return redirect()->route('basket', $request->only(['per', 'page']));
    }

    public function empty(Request $request): RedirectResponse
    {
        UsersRelease::query()->where('users_id', $request->user()->id)->delete();
        Cache::forget('composer_user_'.$request->user()->id);

        return redirect()->route('basket')->with('success', 'Basket emptied.');
    }

    public function download(Request $request, GetNzbController $downloads): Response|Builder
    {
        $guids = app(ReleaseBrowserQuery::class)->matchingQuery($this->basketState($request), $this->userdata)->pluck('r.guid');
        if ($guids->isEmpty()) {
            return redirect()->route('basket')->with('info', 'Your basket is empty.');
        }
        $request->merge(['id' => $guids->implode(','), 'zip' => '1']);
        $request->attributes->set(GetNzbController::REQUEST_USER_ATTRIBUTE, $request->user());

        return $downloads->getNzb($request);
    }

    private function basketState(Request $request): ReleaseBrowserState
    {
        $listing = Request::create($request->url(), 'GET', $request->only(['per', 'page']));

        return ReleaseBrowserState::fromRequest($listing, BrowseRoot::All, $this->userdata, basketOnly: true, tableOnly: true);
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
                : redirect()->route('basket');
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

        return redirect()->route('basket');
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

        return redirect()->route('basket');
    }
}
