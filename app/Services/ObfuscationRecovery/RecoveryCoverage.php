<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class RecoveryCoverage
{
    /**
     * @param  list<array<string, mixed>>  $headers
     * @return array{returned: list<array{int,int}>, first_postdate: ?string, last_postdate: ?string, earliest_date_article:?int, latest_date_article:?int, date_order_consistent:bool, date_points:list<array{int,string}>}
     */
    public static function overview(array $headers, int $first, int $last): array
    {
        $numbers = $dates = [];
        $usableDates = true;
        foreach ($headers as $header) {
            $raw = $header['Number'] ?? null;
            $number = (is_string($raw) || is_int($raw))
                ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $first, 'max_range' => $last]]) : false;
            if ($number === false) {
                throw new InvalidArgumentException('invalid_overview_range');
            }
            $numbers[] = [$number, $number];
            $date = self::sourceDate($header['Date'] ?? null);
            if ($date === null || (isset($dates[$number]) && $dates[$number] !== $date)) {
                $usableDates = false;
            } else {
                $dates[$number] = $date;
            }
        }

        ksort($dates, SORT_NUMERIC);
        $previous = null;
        foreach ($dates as $date) {
            if ($previous !== null && $date < $previous) {
                $usableDates = false;
            }
            $previous = $date;
        }

        return ['returned' => self::merge($numbers),
            'first_postdate' => $usableDates && $dates !== [] ? min($dates) : null,
            'last_postdate' => $usableDates && $dates !== [] ? max($dates) : null,
            'earliest_date_article' => $usableDates && $dates !== [] ? array_key_first($dates) : null,
            'latest_date_article' => $usableDates && $dates !== [] ? array_key_last($dates) : null,
            'date_order_consistent' => $usableDates,
            'date_points' => $usableDates ? array_map(static fn (int $number, string $date): array => [$number, $date], array_keys($dates), array_values($dates)) : []];
    }

    public static function sourceDate(mixed $raw): ?string
    {
        if (! is_string($raw) || strlen($raw) > 128) {
            return null;
        }
        $date = date_parse($raw);
        if ($date['error_count'] > 0 || $date['warning_count'] > 0 || $date['year'] === false
            || $date['year'] < 1970 || $date['year'] > 9999 || $date['month'] === false || $date['day'] === false
            || $date['hour'] === false || $date['minute'] === false || $date['second'] === false || ! ($date['is_localtime'] ?? false)) {
            return null;
        }

        return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param list<array{int,int}> $ranges
     * @return list<array{int,int}>
     */
    public static function merge(array $ranges): array
    {
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ranges as [$first, $last]) {
            if ($first < 1 || $last < $first || $last === PHP_INT_MAX) {
                throw new InvalidArgumentException('invalid_coverage_range');
            }
            $index = count($merged) - 1;
            if ($index >= 0 && $first <= $merged[$index][1] + 1) {
                $merged[$index][1] = max($last, $merged[$index][1]);
            } else {
                $merged[] = [$first, $last];
            }
        }

        return $merged;
    }

    /** @param list<array{int,int}> $positive
     * @return list<array{int,int}>
     */
    public static function holes(int $first, int $last, array $positive): array
    {
        $holes = [];
        foreach (self::merge($positive) as [$start, $end]) {
            if ($end < $first || $start > $last) {
                continue;
            }
            if ($start > $first) {
                $holes[] = [$first, $start - 1];
            }
            $first = max($first, $end + 1);
        }
        if ($first <= $last) {
            $holes[] = [$first, $last];
        }

        return $holes;
    }
}
