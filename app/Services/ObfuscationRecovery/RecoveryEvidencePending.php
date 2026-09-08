<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final readonly class RecoveryEvidencePending
{
    public function __construct(public string $nextAttemptAt) {}
}
