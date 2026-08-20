<?php

declare(strict_types=1);

namespace App\Services\Nzb;

/**
 * How complete a release is, as what we hold over what was declared.
 *
 * The unit is the caller's: normal posts count segments, while the obfuscated single-segment
 * style counts files (see {@see CompletionSignals}). The arithmetic is the same either way, so
 * this deliberately does not name one.
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
     * @param  int  $held  What we actually have: segments, or files.
     * @param  int  $declared  How many of those the subjects declared.
     */
    public static function percentage(int $held, int $declared): float
    {
        if ($declared <= 0) {
            return 0.0;
        }

        return min(100.0, ($held / $declared) * 100);
    }
}
