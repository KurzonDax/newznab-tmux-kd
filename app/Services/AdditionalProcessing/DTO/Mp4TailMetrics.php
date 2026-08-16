<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

final readonly class Mp4TailMetrics
{
    public function __construct(
        public int $tailsFetched = 0,
        public int $moovFoundCount = 0,
        public int $moovMissingCount = 0,
        public int $tailBytes = 0,
    ) {}

    public function recordFetch(int $bytes): self
    {
        return new self(
            $this->tailsFetched + 1,
            $this->moovFoundCount,
            $this->moovMissingCount,
            $this->tailBytes + max($bytes, 0),
        );
    }

    public function recordFound(): self
    {
        return new self(
            $this->tailsFetched,
            $this->moovFoundCount + 1,
            $this->moovMissingCount,
            $this->tailBytes,
        );
    }

    public function recordMissing(): self
    {
        return new self(
            $this->tailsFetched,
            $this->moovFoundCount,
            $this->moovMissingCount + 1,
            $this->tailBytes,
        );
    }

    public function merge(self $other): self
    {
        return new self(
            $this->tailsFetched + $other->tailsFetched,
            $this->moovFoundCount + $other->moovFoundCount,
            $this->moovMissingCount + $other->moovMissingCount,
            $this->tailBytes + $other->tailBytes,
        );
    }
}
