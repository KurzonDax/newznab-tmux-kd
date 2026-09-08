<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Models\Release;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseRepair\RecoveryLease;
use Throwable;

final class RecoveryNzbRestore
{
    public function run(Release $release): string
    {
        if (! Release::query()->whereKey($release->id)->where('guid', $release->guid)->exists()) {
            return 'unavailable';
        }
        $lease = RecoveryLease::acquire($release);
        if ($lease === null) {
            return 'busy';
        }
        try {
            return $this->withLease($release, $lease);
        } finally {
            $lease->release();
        }
    }

    public function withLease(Release $release, RecoveryLease $lease): string
    {
        $publication = app(RecoveryIdentityPolicy::class)->publication((int) $release->id);
        if ($publication === null || $publication->state !== 'published' || $publication->deleted_at !== null
            || ! $lease->owns((int) $release->id)) {
            return 'unavailable';
        }
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        $nzb = app(NzbService::class);
        $path = $nzb->nzbPath($release->guid);
        if ($path !== false) {
            try {
                app(RecoveryNzbVerifier::class)->verify($path, $plan);

                return 'unchanged';
            } catch (Throwable) {
                // Only the exact retained membership can replace the damaged document.
            }
        }

        return $nzb->restoreRecoveryManifest($release, $lease, $plan)->success ? 'restored' : 'storage_failure';
    }
}
