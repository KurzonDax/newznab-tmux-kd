<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Admin\StorePosterIdentityBlacklistRequest;
use App\Services\BlacklistSweepService;
use App\Services\PosterIdentityBlacklistService;
use App\Services\PosterIdentityBrowserContext;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * An administrator's blacklist action on a poster's list (docs/proposals/generic-release-lists/SPEC.md
 * 5.8); the list itself is GenericReleasesController::poster(). A started sweep is reported only
 * in the list's pager line (the run the start returned, kept in the session with the rule and
 * the exact poster), never doubled by a flash; a sweep not started or unable to start stays a
 * flash message.
 */
final class PosterIdentityController extends BasePageController
{
    public function __construct(
        private readonly PosterIdentityBlacklistService $posterIdentityBlacklist,
        private readonly BlacklistSweepService $blacklistSweeps,
    ) {
        parent::__construct();
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
        $redirect = redirect()->route('poster-identity', ['name' => $posterIdentity]);

        if (! $request->boolean('delete_releases')) {
            return $redirect->with('success', 'Rule #'.$ruleId.' added · sweep not started');
        }
        try {
            $run = $this->blacklistSweeps->start('delete', $ruleId);
        } catch (RuntimeException) {
            return $redirect->with('success', 'Rule #'.$ruleId.' added · sweep could not start');
        }
        $request->session()->put(PosterIdentityBrowserContext::SESSION_KEY, ['run' => $run['id'], 'rule' => $ruleId, 'poster' => $posterIdentity]);

        return $redirect;
    }
}
