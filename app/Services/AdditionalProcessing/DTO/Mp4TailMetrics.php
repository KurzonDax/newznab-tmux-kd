<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

final readonly class Mp4TailMetrics
{
    public function __construct(
        public int $tailFetched = 0,
        public int $moovFound = 0,
        public int $moovMissing = 0,
        public int $tailBytes = 0,
    ) {}

    public function recordFetch(int $bytes): self
    {
        return new self(
            $this->tailFetched + 1,
            $this->moovFound,
            $this->moovMissing,
            $this->tailBytes + max($bytes, 0),
        );
    }

    public function recordFound(): self
    {
        return new self(
            $this->tailFetched,
            $this->moovFound + 1,
            $this->moovMissing,
            $this->tailBytes,
        );
    }

    public function recordMissing(): self
    {
        return new self(
            $this->tailFetched,
            $this->moovFound,
            $this->moovMissing + 1,
            $this->tailBytes,
        );
    }

    public function merge(self $other): self
    {
        return new self(
            $this->tailFetched + $other->tailFetched,
            $this->moovFound + $other->moovFound,
            $this->moovMissing + $other->moovMissing,
            $this->tailBytes + $other->tailBytes,
        );
    }
}
