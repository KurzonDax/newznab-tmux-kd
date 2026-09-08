<?php

declare(strict_types=1);

namespace App\Services\Par2Sidecar;

use App\Models\Release;
use App\Services\CollectionReconciliation\BundleIdentity;
use App\Services\ObfuscationRecovery\RecoveryIdentityPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SidecarEligibility
{
    public function allows(int $releaseId): bool
    {
        if (! Release::query()->whereKey($releaseId)->where('nzbstatus', 1)
            ->whereRaw(RecoveryIdentityPolicy::ordinarySql('releases.id'))
            ->whereRaw(BundleIdentity::availableSql())->exists()) {
            return false;
        }
        if (Schema::hasTable('reconciled_postings') && DB::table('reconciled_postings')->where('release_id', $releaseId)->exists()) {
            return false;
        }

        return ! Schema::hasTable('reconciled_posting_inputs') || ! DB::table('reconciled_posting_inputs')->where('release_id', $releaseId)->exists();
    }
}
