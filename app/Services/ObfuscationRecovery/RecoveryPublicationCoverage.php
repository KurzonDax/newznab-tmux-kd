<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final class RecoveryPublicationCoverage
{
    public function ready(object $bundle): bool
    {
        if ($bundle->capture_generation === null) {
            return true;
        }
        $coverage = json_decode($bundle->coverage_evidence ?? 'null', true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($coverage) || $bundle->manifest_verified_at === null || $bundle->sealed_plan === null) {
            return false;
        }

        return (new RecoverySettlement)->assess($bundle->source_epoch, (int) $bundle->groups_id, (int) $bundle->capture_generation,
            $coverage['first_article'], $coverage['last_article'], $coverage['first_postdate'], $coverage['last_postdate'],
            $coverage['changed_at'], true) === 'ready';
    }
}
