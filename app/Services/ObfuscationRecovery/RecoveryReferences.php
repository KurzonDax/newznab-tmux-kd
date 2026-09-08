<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;
use LogicException;

final class RecoveryReferences
{
    public function retain(string $ownerType, string $ownerKey, string $resourceType, string $digest): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('reference_requires_owner_transaction');
        }
        $identity = (new RecoveryIdentity)->digest([$ownerType, $ownerKey, $resourceType, $digest]);
        if ($resourceType === 'artifact') {
            DB::table('obfuscation_recovery_artifacts')->where('digest', $digest)->lockForUpdate()->first();
        } else {
            DB::table('obfuscation_recovery_evidence')->where('message_id_digest', $digest)->lockForUpdate()->first();
        }
        DB::table('obfuscation_recovery_references')->insertOrIgnore([
            'identity' => $identity, 'owner_type' => $ownerType, 'owner_key' => $ownerKey,
            'resource_type' => $resourceType, 'resource_digest' => $digest,
        ]);
    }

    public function plan(string $ownerType, int $ownerId, RecoveryPlan $plan): void
    {
        $this->retain($ownerType, (string) $ownerId, 'artifact', $plan->manifestDigest);
        foreach ($plan->evidenceIds as $messageId) {
            $this->retain($ownerType, (string) $ownerId, 'evidence', hash('sha256', $messageId));
        }
    }

    public function release(string $ownerType, string $ownerKey): void
    {
        DB::table('obfuscation_recovery_references')->where('owner_type', $ownerType)->where('owner_key', $ownerKey)->delete();
    }
}
