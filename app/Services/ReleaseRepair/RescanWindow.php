<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

/**
 * The slice of a group's article numbering one release's re-scan is allowed to read.
 */
final readonly class RescanWindow
{
    public function __construct(
        public int $first,
        public int $last,
        public bool $anchored,
    ) {}

    /**
     * Articles the window spans -- what the per-release budget is measured against.
     */
    public function width(): int
    {
        return max(0, $this->last - $this->first + 1);
    }
}
