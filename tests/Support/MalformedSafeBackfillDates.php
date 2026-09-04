<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The stored `safebackfilldate` values that are not a real `YYYY-MM-DD` calendar date.
 *
 * The admin form rejects them on save and the backfill pass fails closed on read, and those
 * two sides have to agree about what counts as a real date -- a value the form accepts but
 * the pass refuses to parse turns every pass into a warning-only no-op. Sharing one list is
 * what keeps them from drifting apart.
 */
final class MalformedSafeBackfillDates
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function cases(): array
    {
        return [
            'wrong field order' => ['14-08-2012'],
            'stray text' => ['sometime last year'],
            'trailing time' => ['2012-08-14 00:00:00'],
            'not a calendar date' => ['2012-02-31'],
            'unpadded' => ['2012-8-14'],
        ];
    }
}
