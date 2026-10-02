<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use DariusIII\NetNntp\Error as NntpError;
use RuntimeException;

/**
 * Finds the article number at a point in time on one server, by XOVER alone.
 *
 * Each probe is dated by the median `Date` of a short range, so a few posts carrying an old
 * date cannot steer the bisection. It never reads the local database: article numbers are
 * per-server, so stored parts say nothing about another server's numbering.
 */
final class ArticleTimeLocator
{
    /** Articles read per probe. */
    private const int PROBE_SPAN = 200;

    /** Fewer dated lines than this and a probe has no date. */
    private const int MIN_DATED_LINES = 20;

    /** Stop once the bracket is this narrow: callers tolerate an error of hours. */
    private const int RESOLUTION = 2_000;

    /** Bisection steps; 2^45 covers any article range a server can hold. */
    private const int MAX_STEPS = 45;

    /**
     * @param  array{first: int|string, last: int|string}  $group  selectGroup() result for the group the connection has selected
     * @return int An article at or before $goalTime, within RESOLUTION of it; the group's first
     *             article when no probe could be dated.
     */
    public function locate(NNTPService $nntp, array $group, int $goalTime): int
    {
        $lo = (int) $group['first'];
        $hi = (int) $group['last'];

        for ($step = 0; $hi - $lo > self::RESOLUTION && $step < self::MAX_STEPS; $step++) {
            $mid = intdiv($lo + $hi, 2);
            $date = $this->dateAt($nntp, $mid);

            // An unknown date counts as "too late, look earlier": starting too early only
            // re-reads headers storage ignores as duplicates, starting too late skips posts.
            if ($date !== null && $date < $goalTime) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    private function dateAt(NNTPService $nntp, int $article): ?int
    {
        $lines = $nntp->getXOVER($article.'-'.($article + self::PROBE_SPAN - 1));
        // An NNTP error is a broken connection; a missing article only shortens the list.
        if ($lines instanceof NntpError) {
            throw new RuntimeException(sprintf(
                'XOVER failed on NNTP provider %s while locating an article by date: %s',
                $nntp->provider()->name,
                $lines->getMessage(),
            ));
        }

        $dates = [];
        foreach (\is_array($lines) ? $lines : [] as $line) {
            $date = \is_array($line) ? strtotime((string) ($line['Date'] ?? '')) : false;
            if ($date !== false) {
                $dates[] = $date;
            }
        }

        if (\count($dates) < self::MIN_DATED_LINES) {
            return null;
        }

        sort($dates);

        return $dates[intdiv(\count($dates), 2)];
    }
}
