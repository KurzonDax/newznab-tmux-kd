<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\GenericListContext;
use App\Data\GenericReleaseFilters;
use App\Data\ReleaseListFilters;
use App\Models\Category;
use App\Models\User;
use App\Services\PosterIdentityBrowserContext;
use App\Services\Releases\GenericReleaseList;
use App\Services\Releases\GenericReleaseRows;
use App\Services\Releases\RememberedListFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The generic release lists (docs/proposals/generic-release-lists/SPEC.md 5): All releases
 * (GET /browse/all, with ?group= for a group's releases and ?poster= for a poster's posts), a
 * poster's posts (GET /poster?name=) and Other releases (GET /browse/other and /browse/other/{id},
 * rendered for BrowseController::show()). One list form in four contexts.
 */
final class GenericReleasesController extends BasePageController
{
    public function __construct(
        private readonly GenericReleaseList $list,
        private readonly GenericReleaseRows $rows,
        private readonly PosterIdentityBrowserContext $posters,
    ) {
        parent::__construct();
    }

    /** All releases; `?group=` reads as today's group input, `?poster=` byte for byte from the raw query string (ReleaseBrowserState). */
    public function all(Request $request): View|RedirectResponse
    {
        $poster = self::rawQuery($request, 'poster');
        $group = $request->input('group', '');
        $context = match (true) {
            $poster !== '' => GenericListContext::poster($poster),
            is_string($group) && $group !== '' => GenericListContext::group($group),
            default => GenericListContext::all($request->boolean('watching')),
        };

        return $this->list($request, $this->userdata, $context);
    }

    /** A poster's posts at /poster?name=; without a name the empty list, as today. */
    public function poster(Request $request): View|RedirectResponse
    {
        return $this->list($request, $this->userdata, GenericListContext::poster(self::rawQuery($request, 'name'), 'name'));
    }

    /**
     * Other releases, for BrowseController::show(): a sub-category in the address (the header's
     * /browse/other/10) opens the list's canonical address with that Category set, an explicit
     * choice the list's own URLs then carry.
     */
    public function other(Request $request, User $user, ?Category $category = null): View|RedirectResponse
    {
        if ($category !== null) {
            return redirect()->route('browse', ['parentCategory' => GenericListContext::OTHER, ...$request->query(), 'category' => [(int) $category->id]]);
        }

        return $this->list($request, $user, GenericListContext::other());
    }

    private function list(Request $request, User $user, GenericListContext $context): View|RedirectResponse
    {
        $exclusions = array_values(array_map('intval', (array) $user->categoryexclusions));
        $sort = $user->releaseViewPreferences($context->preferenceRoot())['sort'] ?? null;
        $noIdentity = $context->isPoster() && $context->key === '';
        $menu = $noIdentity ? [] : $this->list->categoryMenu($context, $exclusions, (int) $user->id);
        $read = static fn (Request $source): GenericReleaseFilters => GenericReleaseFilters::forList($source, array_keys($menu), $sort, $context);
        if ($noIdentity) {
            $filters = $read($request);
        } else {
            // The name search and today's minc links are never remembered, but a bare open's redirect to the remembered
            // filters keeps them; a carried minc displaces the remembered Completion (issue #1032 correction 3); Clear all
            // keeps the list's identity (correction 4).
            $filters = (new RememberedListFilters($context->preferenceRoot(), $context->route, GenericReleaseFilters::KEYS,
                [GenericReleaseFilters::SEARCH, GenericReleaseFilters::MINC], $context->routeParameters(), [GenericReleaseFilters::MINC => ['completion']]))
                ->open($request, $user, $read);
            if ($filters instanceof RedirectResponse) {
                return $filters;
            }
        }
        $total = $noIdentity ? 0 : $this->list->count($filters, $exclusions, (int) $user->id);
        $lastPage = max(1, (int) ceil($total / ReleaseListFilters::PER_PAGE));
        if ($filters->page > $lastPage) {
            return redirect()->route($context->route, $filters->query($lastPage));
        }
        $poster = ['posterIdentity' => '', 'blacklistRule' => null, 'blacklistPreview' => null, 'blacklistPreviewToken' => null, 'sweep' => null];
        if ($context->isPoster() && ! $noIdentity) {
            $poster = $this->posters->forIdentity($context->key, $user, $request->session()->get(PosterIdentityBrowserContext::SESSION_KEY));
            if (($poster['sweep']['state'] ?? null) === PosterIdentityBrowserContext::UNAVAILABLE) {
                $request->session()->forget(PosterIdentityBrowserContext::SESSION_KEY);
            }
        }
        $data = array_merge($this->viewData, $poster, [
            'meta_title' => $context->heading(),
            'heading' => $context->heading(),
            'context' => $context,
            'listRoute' => $context->route,
            'preferenceRoot' => $context->preferenceRoot(),
            'emptyText' => $context->emptyText(),
            'filters' => $filters,
            'matching' => $filters->describe($menu),
            'categoryMenu' => $menu,
            'excludableOther' => $context->isOther() ? null : GenericReleaseFilters::excludableRoot(array_keys($menu)),
            'total' => $total,
            'lastPage' => $lastPage,
            'rows' => $total === 0 ? [] : $this->rows->load($this->list->pageIds($filters, $exclusions, (int) $user->id, $total), $filters->sortsByAdded()),
            'nzbLinkBase' => url('/api/v1/api'),
            'apiToken' => (string) $user->api_token,
            'filtersClock' => RememberedListFilters::clock(),
        ]);

        return view($request->query('_fragment') === 'list' ? 'generic.releases.list' : 'generic.releases.index', $data);
    }

    /** A query value byte for byte, before the request's trimming (ReleaseBrowserState::fromRequest()). */
    private static function rawQuery(Request $request, string $key): string
    {
        parse_str((string) $request->server('QUERY_STRING', ''), $query);
        $value = $query[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
