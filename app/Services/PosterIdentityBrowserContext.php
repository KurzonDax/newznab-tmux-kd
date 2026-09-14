<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

final class PosterIdentityBrowserContext
{
    public function __construct(
        private readonly PosterIdentityBlacklistService $blacklist,
        private readonly BlacklistSweepService $sweeps,
    ) {}

    /** @return array<string, mixed> */
    public function forIdentity(string $posterIdentity, User $user, bool $showSweepStatus): array
    {
        $rule = $posterIdentity !== '' && $user->hasRole('Admin')
            ? $this->blacklist->matchingRule($posterIdentity) : null;
        $confirmation = $posterIdentity !== '' && $user->hasRole('Admin') && $rule === null
            ? $this->blacklist->previewForConfirmation($posterIdentity, (string) $user->username) : null;

        return [
            'posterIdentity' => $posterIdentity,
            'blacklistRule' => $rule,
            'blacklistPreview' => $confirmation['preview'] ?? null,
            'blacklistPreviewToken' => $confirmation['token'] ?? null,
            'showSweepStatus' => $showSweepStatus,
            'sweepStatus' => $showSweepStatus ? $this->sweeps->publicStatus() : null,
        ];
    }
}
