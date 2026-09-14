<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;

final class RecoveryFrontierContinuation
{
    public function consume(RecoveryWorkClaim $claim): void
    {
        DB::transaction(function () use ($claim): void {
            $bundle = (new RecoveryOwnership)->locked($claim);
            if ($bundle !== null) {
                $this->observe($bundle, $claim->stage, true);
            }
        }, 1);
    }

    /** Called while holding the current bundle lock; a revision/stage consumes each wait once. */
    public function observe(object $bundle, RecoveryStage $stage, bool $ready): void
    {
        $scope = (new RecoveryIdentity)->digest(['frontier-continuation', (string) $bundle->id, (string) $bundle->revision, $stage->value]);
        $progress = DB::table('obfuscation_recovery_frontier_progress');
        $created = $progress->insertOrIgnore(['scope' => $scope, 'cursor' => (int) $ready]);
        $changed = $progress->where('scope', $scope)->where('cursor', (int) ! $ready)->update(['cursor' => (int) $ready]);
        if ($ready && $created === 0 && $changed === 1) {
            RecoveryWork::wake(DB::table('obfuscation_recovery_work')->where('bundle_id', $bundle->id)
                ->where('revision', $bundle->revision)->where('stage', $stage->value)
                ->where('purpose', $stage === RecoveryStage::Publish ? 'publish' : 'prepare'));
        }
    }
}
