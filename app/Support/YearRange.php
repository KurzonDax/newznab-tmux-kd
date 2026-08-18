<?php

declare(strict_types=1);

namespace App\Support;

final readonly class YearRange
{
    private const MIN_YEAR = 1900;

    public function __construct(public ?int $from, public ?int $to) {}

    public function startDate(): ?string
    {
        return $this->from !== null ? $this->from.'-01-01' : null;
    }

    public function endDate(): ?string
    {
        return $this->to !== null ? $this->to.'-12-31' : null;
    }

    public static function fromInput(mixed $year, mixed $yearFrom = null, mixed $yearTo = null, ?int $maxYear = null): ?self
    {
        if (! is_scalar($year)) {
            return null;
        }

        $selection = trim((string) $year);
        $maxYear ??= (int) date('Y') + 1;

        if ($selection === 'custom') {
            $from = self::parseYear($yearFrom, $maxYear);
            $to = self::parseYear($yearTo, $maxYear);

            if ($from === null && $to === null) {
                return null;
            }

            if ($from !== null && $to !== null && $from > $to) {
                [$from, $to] = [$to, $from];
            }

            return new self($from, $to);
        }

        if (preg_match('/^(\d{4})s$/', $selection, $matches) === 1) {
            $start = (int) $matches[1];
            $currentDecade = intdiv($maxYear, 10) * 10;
            if ($start < self::MIN_YEAR || $start % 10 !== 0 || $start > $currentDecade) {
                return null;
            }

            return new self($start, $start + 9);
        }

        $singleYear = self::parseYear($selection, $maxYear);

        return $singleYear === null ? null : new self($singleYear, $singleYear);
    }

    /**
     * @return list<string>
     */
    public static function decades(?int $currentYear = null): array
    {
        $currentYear ??= (int) date('Y');
        $currentDecade = intdiv($currentYear, 10) * 10;
        $decades = [];

        for ($year = self::MIN_YEAR; $year <= $currentDecade; $year += 10) {
            $decades[] = $year.'s';
        }

        return $decades;
    }

    private static function parseYear(mixed $value, int $maxYear): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if (preg_match('/^\d{4}$/', $value) !== 1) {
            return null;
        }

        $year = (int) $value;

        return $year >= self::MIN_YEAR && $year <= $maxYear ? $year : null;
    }
}
