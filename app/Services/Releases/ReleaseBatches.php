<?php

declare(strict_types=1);

namespace App\Services\Releases;

/**
 * The same-title batch expander of the release lists (TV SPEC appendix A, Movies SPEC 5.8):
 * within a page, consecutive releases of one show or film whose sort date falls on the same
 * calendar day (application timezone) form a run. A run longer than four shows three rows and
 * an expander for the rest. Releases with no matched title never join a run.
 */
final class ReleaseBatches
{
    public const SHOWN = 3;

    public const COLLAPSES_ABOVE = 4;

    /**
     * @template TRow of object{id: int, day: string}
     *
     * @param  list<TRow>  $rows  in display order
     * @param  callable(TRow): ?int  $titleId  the row's show or film, null for none
     * @param  callable(TRow): string  $titleName  the show or film the expander names
     * @return list<array{key: string, title: string, rows: list<TRow>, collapsible: bool}>
     */
    public static function group(array $rows, callable $titleId, callable $titleName): array
    {
        $runs = [];
        foreach ($rows as $row) {
            $last = array_key_last($runs);
            $previous = $last === null ? null : $runs[$last]['rows'][0];
            if ($previous !== null && $titleId($row) !== null && $titleId($previous) === $titleId($row) && $previous->day === $row->day) {
                $runs[$last]['rows'][] = $row;

                continue;
            }
            $runs[] = ['key' => $titleId($row).'|'.$row->day.'|'.$row->id, 'title' => $titleName($row), 'rows' => [$row], 'collapsible' => false];
        }

        return array_map(static fn (array $run): array => [...$run, 'collapsible' => count($run['rows']) > self::COLLAPSES_ABOVE], $runs);
    }
}
