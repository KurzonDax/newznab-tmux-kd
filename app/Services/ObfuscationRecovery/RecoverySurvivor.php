<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Models\Release;
use App\Services\Nzb\NzbService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RecoverySurvivor
{
    public function inspect(int $publicationId): void
    {
        DB::transaction(function () use ($publicationId): void {
            $publication = DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->lockForUpdate()->first();
            if ($publication === null || ! in_array($publication->state, ['absorbed', 'duplicate_policy_discarded'], true)) {
                return;
            }
            $release = Release::query()->whereKey($publication->releases_id)->where('guid', $publication->guid)->lockForUpdate()->first();
            $path = $release === null ? false : app(NzbService::class)->nzbPath($release->guid);
            $outcome = 'missing_nzb';
            $digest = null;
            if (is_string($path)) {
                $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
                try {
                    $digest = app(RecoveryNzbVerifier::class)->verify($path, $plan, true);
                    $outcome = 'verified_constituent';
                    try {
                        app(RecoveryNzbVerifier::class)->verify($path, $plan);
                        $outcome = 'verified_exact_inventory';
                    } catch (RuntimeException) {
                        // Additional survivor files do not change the ordinary duplicate disposition.
                    }
                } catch (RuntimeException) {
                    $outcome = 'membership_not_verified';
                }
            }
            DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->update([
                'survivor_membership' => $outcome, 'survivor_nzb_digest' => $digest, 'updated_at' => now(),
            ]);
        }, 1);
    }
}
