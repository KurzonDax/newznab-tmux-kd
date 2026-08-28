<?php

declare(strict_types=1);

namespace App\Support\Data;

use App\Enums\DuplicateAbsorbOutcome;
use App\Services\Releases\ReleaseDuplicateAbsorber;

/**
 * Result of {@see ReleaseDuplicateAbsorber} calls.
 *
 * `reason` is set only for failed absorbs. `attempts` is the durable
 * attempted-failure count recorded against the incoming collection after this
 * call; it stays 0 for every other outcome and for absorbs with no backing
 * collection (the NZB import path).
 */
final readonly class DuplicateAbsorbResult
{
    private function __construct(
        public DuplicateAbsorbOutcome $outcome,
        public string $reason,
        public int $attempts,
    ) {}

    public static function absorbed(): self
    {
        return new self(DuplicateAbsorbOutcome::Absorbed, '', 0);
    }

    public static function notBetter(): self
    {
        return new self(DuplicateAbsorbOutcome::NotBetter, '', 0);
    }

    public static function deferred(): self
    {
        return new self(DuplicateAbsorbOutcome::Deferred, '', 0);
    }

    public static function failed(string $reason, int $attempts = 0): self
    {
        return new self(DuplicateAbsorbOutcome::Failed, $reason, $attempts);
    }

    public function wasAbsorbed(): bool
    {
        return $this->outcome === DuplicateAbsorbOutcome::Absorbed;
    }
}
