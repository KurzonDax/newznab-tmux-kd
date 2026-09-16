<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\SevenZip;

use App\Services\NNTP\Contracts\ArticleReadBudget;

/** One release-wide budget, including failed provider attempts. */
final class InspectionBudget implements ArticleReadBudget
{
    private int $articles = 0;

    private int $bytes = 0;

    public readonly float $deadline;

    public function __construct(
        public readonly int $maxArticles,
        public readonly int $maxBytes,
        int $maxSeconds,
    ) {
        $this->deadline = microtime(true) + $maxSeconds;
    }

    public function reserve(int $bytes): bool
    {
        if ($bytes < 1 || $bytes > $this->remainingBytes() || $this->articles >= $this->maxArticles
            || microtime(true) >= $this->deadline) {
            return false;
        }
        $this->articles++;
        $this->bytes += $bytes;

        return true;
    }

    public function settle(int $reserved, int $actual): void
    {
        $this->bytes -= max(0, $reserved - max(0, $actual));
    }

    public function remainingBytes(): int
    {
        return max(0, $this->maxBytes - $this->bytes);
    }
}
