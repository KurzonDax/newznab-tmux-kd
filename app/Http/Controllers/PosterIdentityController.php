<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\ReleaseBrowserState;
use App\Enums\BrowseRoot;
use App\Http\Requests\Admin\StorePosterIdentityBlacklistRequest;
use App\Services\BlacklistSweepService;
use App\Services\PosterIdentityBlacklistService;
use App\Services\PosterIdentityBrowserContext;
use App\Services\Releases\ReleaseBrowserQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use RuntimeException;

final class PosterIdentityController extends BasePageController
{
    public function __construct(
        private readonly PosterIdentityBlacklistService $posterIdentityBlacklist,
        private readonly BlacklistSweepService $blacklistSweeps,
    ) {
        parent::__construct();
    }

    /**
     * @throws \Exception
     */
    public function __invoke(Request $request): View|RedirectResponse
    {
        $state = ReleaseBrowserState::fromRequest($request, BrowseRoot::All, $this->userdata);
        $posterIdentity = $state->posterIdentity;
        $results = $posterIdentity === ''
            ? new LengthAwarePaginator([], 0, $state->per, 1, ['path' => $request->url(), 'query' => $request->query()])
            : app(ReleaseBrowserQuery::class)->paginate($state, $this->userdata);
        if ($state->page > $results->lastPage()) {
            return redirect()->to($state->pageUrl($request, $results->lastPage()));
        }
        $context = app(PosterIdentityBrowserContext::class)->forIdentity($posterIdentity, $this->userdata, $request->session()->get('poster_identity_blacklist_sweep_started') === true);

        return view('poster-identity.index', array_merge($this->viewData, $context, [
            'results' => $results, 'browserState' => $state,
            'meta_title' => $posterIdentity === '' ? 'Posted By' : 'Posts by '.$posterIdentity,
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
