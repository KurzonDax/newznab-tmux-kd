<?php

declare(strict_types=1);

namespace App\Services\Nzb;

/**
 * Segments an NZB holds against the segments its subjects declare.
 */
final readonly class NzbCompletionMeasurement
{
    public function __construct(
        public int $actualParts,
        public int $declaredParts,
    ) {}

    /**
     * Completion as a 0-100 percentage, `0` meaning "never measured".
     */
    public function percentage(): float
    {
        return ReleaseCompletion::percentage($this->actualParts, $this->declaredParts);
    }

    /**
     * Did anything declare a part total?
     *
     * Without a denominator there is nothing to measure, and the release keeps the `0`
     * sentinel rather than being recorded as 0% complete.
     */
    public function isMeasurable(): bool
    {
        return $this->declaredParts > 0;
    }
}
