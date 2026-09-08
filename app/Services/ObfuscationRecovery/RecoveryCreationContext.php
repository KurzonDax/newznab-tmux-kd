<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final readonly class RecoveryCreationContext
{
    public function __construct(public int $publicationId, public int $collectionId, public string $guid) {}
}
