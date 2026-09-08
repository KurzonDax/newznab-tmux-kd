<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Models\Release;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseCreationService;
use Illuminate\Support\Facades\DB;

final class RecoveryPublisher
{
    public function run(RecoveryWorkClaim $claim): string
    {
        $result = app(RecoveryMaterialization::class)->run($claim);
        if (! in_array($result, ['materialized', 'policy_blocked', 'created', 'published'], true)) {
            return $this->finish($claim, $result);
        }
        $publicationId = DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->where('revision', $claim->revision)->value('publication_id');
        $publication = DB::table('obfuscation_recovery_publications')->where('id', $publicationId)->first();
        if ($publication === null) {
            return $this->finish($claim, 'publication_missing');
        }
        $result = app(ReleaseCreationService::class)->createRecovered($claim, (int) $publication->id);
        if ($result === 'created' && app(RecoveryWork::class)->heartbeat($claim)) {
            $publication = DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->first();
            $release = Release::query()->where('id', $publication->releases_id)->where('guid', $publication->guid)->first();
            if ($release === null) {
                return $this->finish($claim, 'release_missing');
            }
            $nzb = app(NzbService::class)->createNzbForRelease($release);
            $result = $nzb->success ? 'published' : 'nzb_pending';
        }

        return $this->finish($claim, $result);
    }

    private function finish(RecoveryWorkClaim $claim, string $result): string
    {
        $work = app(RecoveryWork::class);
        if (in_array($result, ['published', 'absorbed', 'duplicate_policy_discarded', 'tombstoned', 'quarantined'], true)) {
            $work->complete($claim, $result);
        } elseif ($result !== 'obsolete') {
            $work->defer($claim, in_array($result, ['canonical_reconciling', 'canonical_reconciled', 'reconciling'], true) ? 1 : 60);
        }

        return $result;
    }
}
