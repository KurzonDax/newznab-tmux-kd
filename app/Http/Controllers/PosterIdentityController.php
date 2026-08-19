<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Admin\StorePosterIdentityBlacklistRequest;
use App\Services\BlacklistSweepService;
use App\Services\PosterIdentityBlacklistService;
use App\Services\Releases\ReleaseBrowseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use RuntimeException;

final class PosterIdentityController extends BasePageController
{
    public function __construct(
        private readonly ReleaseBrowseService $releaseBrowseService,
        private readonly PosterIdentityBlacklistService $posterIdentityBlacklist,
        private readonly BlacklistSweepService $blacklistSweeps,
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
        $blacklistRule = $posterIdentity !== '' && $request->user()?->hasRole('Admin')
            ? $this->posterIdentityBlacklist->matchingRule($posterIdentity)
            : null;
        $blacklistConfirmation = $posterIdentity !== '' && $request->user()?->hasRole('Admin') && $blacklistRule === null
            ? $this->posterIdentityBlacklist->previewForConfirmation($posterIdentity, (string) $request->user()->username)
            : null;
        $showSweepStatus = $request->session()->get('poster_identity_blacklist_sweep_started') === true;

        return view('poster-identity.index', array_merge($this->viewData, [
            'posterIdentity' => $posterIdentity,
            'results' => $results,
            'blacklistRule' => $blacklistRule,
            'blacklistPreview' => $blacklistConfirmation['preview'] ?? null,
            'blacklistPreviewToken' => $blacklistConfirmation['token'] ?? null,
            'showSweepStatus' => $showSweepStatus,
            'sweepStatus' => $showSweepStatus ? $this->blacklistSweeps->publicStatus() : null,
            'meta_title' => $posterIdentity === '' ? 'Posted By' : 'Posted By '.$posterIdentity,
            'meta_keywords' => 'posted by,releases,nzb',
            'meta_description' => 'Browse releases from an exact Posted By identity',
        ]));
    }

    public function storeBlacklist(StorePosterIdentityBlacklistRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $posterIdentity = (string) $validated['name'];
        $rule = $this->posterIdentityBlacklist->createOrEnable(
            $posterIdentity,
            (string) $request->user()?->username,
            (string) $validated['preview_token'],
        );
        $ruleId = (int) $rule->id;
        $message = 'Rule #'.$ruleId.' added · sweep not started';
        $sweepStarted = false;

        if ($request->boolean('delete_releases')) {
            try {
                $this->blacklistSweeps->start('delete', $ruleId);
                $message = 'Rule #'.$ruleId.' added · sweep started';
                $sweepStarted = true;
            } catch (RuntimeException) {
                $message = 'Rule #'.$ruleId.' added · sweep could not start';
            }
        }

        $redirect = redirect()
            ->route('poster-identity', ['name' => $posterIdentity])
            ->with('success', $message);

        if ($sweepStarted) {
            $redirect->with('poster_identity_blacklist_sweep_started', true);
        }

        return $redirect;
    }
}
