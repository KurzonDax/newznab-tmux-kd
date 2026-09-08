<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use RuntimeException;

final class RecoveryCachedInventory
{
    public function validate(string $data, RecoveryPlan $plan): RecoveryInventory
    {
        $inventory = (new RecoveryPar2)->parse($data);
        if (bin2hex($inventory->setId) !== $plan->setId || count($inventory->files) !== $plan->protectedFiles()) {
            throw new RuntimeException('recovery_cached_inventory_mismatch');
        }
        $expected = array_column($plan->files, null, 'identity');
        foreach ($inventory->files as $file) {
            $planned = $expected[bin2hex($file->id)] ?? null;
            if ($planned === null || $planned->role !== $plan->algorithm->payloadRole() || $planned->decodedBytes !== $file->size) {
                throw new RuntimeException('recovery_cached_inventory_mismatch');
            }
        }

        return $inventory;
    }
}
