<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final readonly class RecoveryCaptureReport
{
    /** @param array<string, int> $exclusions */
    public function __construct(
        public string $outcome,
        public int $captured = 0,
        public int $duplicates = 0,
        public bool $coverageComplete = false,
        public array $exclusions = [],
    ) {}
}
