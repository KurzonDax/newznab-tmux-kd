<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final readonly class RecoveryArtifact
{
    public function __construct(public string $digest, public int $bytes)
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1 || $bytes < 0) {
            throw new InvalidArgumentException('invalid_artifact_reference');
        }
    }
}
