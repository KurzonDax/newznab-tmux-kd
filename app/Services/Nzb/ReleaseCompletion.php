<?php

declare(strict_types=1);

namespace App\Services\Nzb;

/**
 * How complete a release is, measured as the segments we hold against the segments its subjects declare.
 */
final class ReleaseCompletion
{
    /**
     * Completion as a 0-100 percentage.
     *
     * `0` is the "never measured" sentinel rather than a real 0%: when nothing declared a part total
     * there is no denominator to measure against, and ReleaseProcessingService::deleteIncompleteReleases()
     * exempts `0` so those releases are not swept as if they were empty.
     *
     * @param  int  $actualParts  Segments actually present.
     * @param  int  $declaredParts  Sum of the part totals declared by the binaries' subjects.
     */
    public static function percentage(int $actualParts, int $declaredParts): float
    {
        if ($declaredParts <= 0) {
            return 0.0;
        }

        return min(100.0, ($actualParts / $declaredParts) * 100);
    }
}
