<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final readonly class RecoveryWorkClaim
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $id,
        public int $bundleId,
        public int $revision,
        public RecoveryStage $stage,
        public string $purpose,
        public string $token,
        public array $payload,
    ) {}
}
