<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\TvReleaseRow;

/**
 * The same-show batch expander (SPEC appendix A): within a page, consecutive releases of one
 * show whose sort date falls on the same calendar day (application timezone) form a run. A run
 * longer than four shows three rows and an expander for the rest. Releases with no matched
 * show never join a run.
 */
final class TvReleaseBatches
{
    public const SHOWN = 3;

    public const COLLAPSES_ABOVE = 4;

    /**
     * @param  list<TvReleaseRow>  $rows  in display order
     * @return list<array{key: string, show: string, rows: list<TvReleaseRow>, collapsible: bool}>
     */
    public static function group(array $rows): array
    {
        $runs = [];
        foreach ($rows as $row) {
            $last = array_key_last($runs);
            $previous = $last === null ? null : $runs[$last]['rows'][0];
            if ($previous !== null && $row->hasShow() && $previous->showId === $row->showId && $previous->day === $row->day) {
                $runs[$last]['rows'][] = $row;

                continue;
            }
            $runs[] = ['key' => $row->showId.'|'.$row->day.'|'.$row->id, 'show' => $row->showTitle, 'rows' => [$row], 'collapsible' => false];
        }

        return array_map(static fn (array $run): array => [...$run, 'collapsible' => count($run['rows']) > self::COLLAPSES_ABOVE], $runs);
    }
}
