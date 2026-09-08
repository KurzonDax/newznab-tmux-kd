<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final readonly class RecoveryCaptureBatch
{
    /**
     * @param  list<array<string, mixed>>  $rawHeaders
     * @param  list<array<string, mixed>>  $acceptedHeaders
     */
    public function __construct(public array $rawHeaders, public array $acceptedHeaders)
    {
        if (count($rawHeaders) > 20000 || count($acceptedHeaders) > count($rawHeaders)) {
            throw new InvalidArgumentException('capture_chunk_limit');
        }
    }
}
