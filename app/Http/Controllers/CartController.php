<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\GenericReleaseFilters;
use App\Data\ReleaseBrowserState;
use App\Data\ReleaseListFilters;
use App\Enums\BrowseRoot;
use App\Models\Release;
use App\Models\UsersRelease;
use App\Services\Releases\GenericReleaseList;
use App\Services\Releases\GenericReleaseRows;
use App\Services\Releases\HiddenCategoryGate;
use App\Services\Releases\ReleaseBrowserQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CartController extends BasePageController
{
    /**
     * The basket (issue #1051): the generic release list (GenericReleasesController's list form) in
     * the basket context. It differs from All releases in its heading, in having no name search
     * field, filter bar or sort menu (its order is the lists' default, newest posted first), in
     * the heading row's Empty basket and Download N NZBs, and in remembering nothing.
     */
    public function index(Request $request, GenericReleaseList $list, GenericReleaseRows $rows): View|RedirectResponse
    {
        $user = $this->userdata;
        $exclusions = array_values(array_map('intval', (array) $user->categoryexclusions));
        $filters = GenericReleaseFilters::forBasket($request);
        $context = $filters->context;
        $total = $list->count($filters, $exclusions, (int) $user->id);
        $lastPage = max(1, (int) ceil($total / ReleaseListFilters::PER_PAGE));
        if ($filters->page > $lastPage) {
            return redirect()->route($context->route, $filters->query($lastPage));
        }

        return view($request->query('_fragment') === 'list' ? 'generic.releases.list' : 'generic.releases.index', [...$this->viewData,
            'meta_title' => $context->heading(),
            'heading' => $context->heading(),
            'context' => $context,
            'listRoute' => $context->route,
            'preferenceRoot' => $context->preferenceRoot(),
            'emptyText' => $context->emptyText(),
            'filters' => $filters,
            'matching' => '',
            'categoryMenu' => [],
            'excludableOther' => null,
            'total' => $total,
            'lastPage' => $lastPage,
            'rows' => $total === 0 ? [] : $rows->load($list->pageIds($filters, $exclusions, (int) $user->id, $total), $filters->sortsByAdded()),
            'nzbLinkBase' => url('/api/v1/api'),
            'apiToken' => (string) $user->api_token,
            'filtersClock' => 0,
            'posterIdentity' => '', 'blacklistRule' => null, 'blacklistPreview' => null, 'blacklistPreviewToken' => null, 'sweep' => null,
        ]);
    }

    public function empty(Request $request): RedirectResponse
    {
        UsersRelease::query()->where('users_id', $request->user()->id)->delete();
        Cache::forget('composer_user_'.$request->user()->id);

        return redirect()->route('basket')->with('success', 'Basket emptied.');
    }

    public function download(Request $request, GetNzbController $downloads): Response
    {
        $guids = app(ReleaseBrowserQuery::class)->matchingQuery($this->basketState(), $this->userdata)->pluck('r.guid');
        if ($guids->isEmpty()) {
            return redirect()->route('basket')->with('info', 'Your basket is empty.');
        }
        $request->merge(['id' => $guids->implode(','), 'zip' => '1']);
        $request->attributes->set(GetNzbController::REQUEST_USER_ATTRIBUTE, $request->user());

        return $downloads->getNzb($request);
    }

    private function basketState(): ReleaseBrowserState
    {
        return ReleaseBrowserState::fromRequest(new Request, BrowseRoot::All, basketOnly: true);
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

        $releases = Release::query()->whereIn('guid', $guids)->get(['id', 'categories_id']);

        if ($releases->isEmpty()) {
            return $request->ajax() || $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'No releases found'], 404)
                : redirect()->route('basket');
        }

        // Releases in categories the user has hidden are never added.
        [$hidden, $visible] = $releases->partition(fn (Release $release): bool => $this->hidesCategory($release->categories_id));
        if ($visible->isEmpty()) {
            return $request->ajax() || $request->wantsJson()
                ? app(HiddenCategoryGate::class)->deniedJson($this->userdata, (int) $hidden->first()->categories_id)
                : redirect()->route('basket');
        }

        $releaseIds = $visible->map(static fn (Release $release): int => (int) $release->id)->values()->all();

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
