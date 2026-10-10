<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Services\Releases\GenericReleaseList;

/**
 * What a poster's list shows an administrator (docs/proposals/generic-release-lists/SPEC.md 5.8):
 * the matching blacklist rule, the confirmation's preview and token, and the status of the
 * blacklist sweep this administrator started from this poster's page, attributed to that run
 * alone (issue #1032 corrections 1 and 5): the run id the start returned, kept in the session
 * with the rule and the exact poster, is the only run read; a later run by anyone, for the same
 * rule or another, never supplies this poster's counts.
 */
final class PosterIdentityBrowserContext
{
    /** The session key of the started sweep: run id, rule id and the exact poster identity. */
    public const string SESSION_KEY = 'poster_identity_blacklist_sweep';

    public const string RUNNING = 'running';

    public const string COMPLETE = 'complete';

    public const string PARTIAL = 'partial';

    public const string FAILED = 'failed';

    public const string UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly PosterIdentityBlacklistService $blacklist,
        private readonly BlacklistSweepService $sweeps,
        private readonly GenericReleaseList $list,
    ) {}

    /**
     * @param  array<string, mixed>|null  $started  the session's started sweep (SESSION_KEY), if any
     * @return array{posterIdentity: string, blacklistRule: mixed, blacklistPreview: mixed, blacklistPreviewToken: mixed, sweep: array<string, mixed>|null}
     */
    public function forIdentity(string $posterIdentity, User $user, ?array $started): array
    {
        $admin = $posterIdentity !== '' && $user->hasRole('Admin');
        $rule = $admin ? $this->blacklist->matchingRule($posterIdentity) : null;
        $confirmation = $admin && $rule === null ? $this->blacklist->previewForConfirmation($posterIdentity, (string) $user->username) : null;

        return [
            'posterIdentity' => $posterIdentity,
            'blacklistRule' => $rule,
            'blacklistPreview' => $confirmation['preview'] ?? null,
            'blacklistPreviewToken' => $confirmation['token'] ?? null,
            'sweep' => $admin ? $this->sweepStatus($started, $posterIdentity) : null,
        ];
    }

    /**
     * The started sweep's status for this poster, or null when none was started from this
     * poster's page: running (an attempt, no promise), complete (exit 0 and no release of the
     * exact poster remains, whatever the viewer's filters), partial (exit 0 but some remain, no
     * guess why), failed (a non-zero exit, only the known removals) or unavailable (the run's
     * metadata cannot be retrieved: nothing is confirmed).
     *
     * @param  array<string, mixed>|null  $started
     * @return array{state: string, rule: int, run: string, removed: int, remaining: ?int, exitCode: ?int}|null
     */
    public function sweepStatus(?array $started, string $posterIdentity): ?array
    {
        if (! is_array($started) || ($started['poster'] ?? null) !== $posterIdentity || ! is_string($started['run'] ?? null)) {
            return null;
        }
        $status = ['rule' => (int) ($started['rule'] ?? 0), 'run' => $started['run'], 'removed' => 0, 'remaining' => null, 'exitCode' => null];
        $run = $this->sweeps->run($started['run']);
        if ($run === null) {
            return [...$status, 'state' => self::UNAVAILABLE];
        }
        $status['removed'] = (int) ($run['removed_count'] ?? 0);
        if (($run['running'] ?? false) === true) {
            return [...$status, 'state' => self::RUNNING];
        }
        $status['exitCode'] = (int) ($run['exit_code'] ?? 255);
        if ($status['exitCode'] !== 0) {
            return [...$status, 'state' => self::FAILED];
        }
        $status['remaining'] = $this->list->remaining($posterIdentity);

        return [...$status, 'state' => $status['remaining'] > 0 ? self::PARTIAL : self::COMPLETE];
    }
}
