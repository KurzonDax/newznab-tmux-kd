<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

final readonly class TvShowResolution
{
    private function __construct(
        public int $videoId,
        private bool $ambiguous,
    ) {}

    public static function matched(int $videoId): self
    {
        return new self($videoId, false);
    }

    public static function notFound(): self
    {
        return new self(0, false);
    }

    public static function ambiguous(): self
    {
        return new self(0, true);
    }

    public function isAmbiguous(): bool
    {
        return $this->ambiguous;
    }
}
