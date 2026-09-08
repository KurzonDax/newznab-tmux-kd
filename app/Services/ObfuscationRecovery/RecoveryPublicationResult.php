<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final readonly class RecoveryPublicationResult
{
    public function __construct(public int $id, public string $identity, public string $outcome) {}
}
