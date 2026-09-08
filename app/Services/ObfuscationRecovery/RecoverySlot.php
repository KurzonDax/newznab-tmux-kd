<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final readonly class RecoverySlot
{
    public function __construct(public int $id, public string $token, public RecoveryProcess $owner) {}
}
