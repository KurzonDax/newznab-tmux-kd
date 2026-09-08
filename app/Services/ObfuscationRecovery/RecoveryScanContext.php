<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Enums\HeaderScanDirection;
use InvalidArgumentException;

final readonly class RecoveryScanContext
{
    public function __construct(
        public int $groupId,
        public string $groupName,
        public string $sourceEpoch,
        public int $generation,
        public int $first,
        public int $last,
        public HeaderScanDirection $direction,
        public string $scanId,
        public int $chunkOrdinal = 0,
        public int $expectedChunks = 1,
    ) {
        if ($groupId < 1 || $generation < 1 || $first < 1 || $last < $first || $last === PHP_INT_MAX
            || $expectedChunks < 1 || $chunkOrdinal < 0 || $chunkOrdinal >= $expectedChunks
            || strlen($sourceEpoch) > 64 || $sourceEpoch === '' || strlen($scanId) > 36 || $scanId === '') {
            throw new InvalidArgumentException('invalid_capture_context');
        }
    }

    public function chunk(int $ordinal): self
    {
        return new self($this->groupId, $this->groupName, $this->sourceEpoch, $this->generation,
            $this->first, $this->last, $this->direction, $this->scanId, $ordinal, $this->expectedChunks);
    }
}
