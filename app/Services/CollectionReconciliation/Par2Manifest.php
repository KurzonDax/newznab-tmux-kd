<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

final readonly class Par2Manifest
{
    /** @param array<string, array{id: string, size: int, full: string, prefix: string}> $files */
    public function __construct(public string $setId, public array $files, public int $packets) {}
}
