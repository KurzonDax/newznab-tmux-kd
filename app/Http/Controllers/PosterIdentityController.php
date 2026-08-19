<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Releases\ReleaseBrowseService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

final class PosterIdentityController extends BasePageController
{
    public function __construct(
        private readonly ReleaseBrowseService $releaseBrowseService,
    ) {
        parent::__construct();
    }

    /**
     * @throws \Exception
     */
    public function __invoke(Request $request): View
    {
        $posterIdentity = $this->scalarInput($request, 'name');
        $orderBy = $this->resolveOrderBy($request, $this->releaseBrowseService->getBrowseOrdering());
        $perPage = (int) config('nntmux.items_per_page');

        $results = $posterIdentity === ''
            ? new LengthAwarePaginator([], 0, $perPage, $this->resolvePage($request), [
                'path' => $request->url(),
                'query' => $request->query(),
            ])
            : $this->releaseBrowseService->getPosterIdentityReleases(
                $posterIdentity,
                $perPage,
                $orderBy,
                (array) $this->userdata->categoryexclusions,
            );

        return view('poster-identity.index', array_merge($this->viewData, [
            'posterIdentity' => $posterIdentity,
            'results' => $results,
            'meta_title' => $posterIdentity === '' ? 'Posted By' : 'Posted By '.$posterIdentity,
            'meta_keywords' => 'posted by,releases,nzb',
            'meta_description' => 'Browse releases from an exact Posted By identity',
        ]));
    }
}
