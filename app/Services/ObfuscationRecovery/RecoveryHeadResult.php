<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final readonly class RecoveryHeadResult
{
    public function __construct(public string $fileId, public string $outcome, public RecoveryCachedPrefix $prefix) {}

    public function pending(): bool
    {
        return $this->outcome === 'enrichment_pending';
    }
}
