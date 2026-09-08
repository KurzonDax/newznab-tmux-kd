<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final readonly class RecoveryReservation
{
    public function __construct(
        public int $attemptId,
        public string $token,
        public int $bytes,
        public int $physicalAttempt,
    ) {}
}
