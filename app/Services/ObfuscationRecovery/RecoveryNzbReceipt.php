<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final readonly class RecoveryNzbReceipt
{
    public function __construct(public int $publicationId, public int $releaseId, public string $guid, public string $manifestDigest, public string $nzbDigest, public string $sealedPlanDigest) {}
}
