<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final readonly class RecoveryInventory
{
    /** @param list<RecoveryProtectedFile> $files */
    public function __construct(public string $setId, public int $sliceSize, public array $files) {}
}
