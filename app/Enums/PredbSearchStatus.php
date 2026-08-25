<?php

namespace App\Enums;

enum PredbSearchStatus: int
{
    case Unsearched = 0;
    case Matched = 1;
    case RetryAfterFirstMiss = -1;
    case RetryAfterSecondMiss = -2;
    case RetryAfterThirdMiss = -3;
    case Parked = -4;
    case Flood = -6;

    public function afterMiss(): self
    {
        return match ($this) {
            self::Unsearched => self::RetryAfterFirstMiss,
            self::RetryAfterFirstMiss => self::RetryAfterSecondMiss,
            self::RetryAfterSecondMiss => self::RetryAfterThirdMiss,
            self::RetryAfterThirdMiss => self::Parked,
            default => $this,
        };
    }

    public function retryDelayDays(): ?int
    {
        return match ($this) {
            self::RetryAfterFirstMiss => 1,
            self::RetryAfterSecondMiss => 2,
            self::RetryAfterThirdMiss => 4,
            default => null,
        };
    }

    /**
     * @return list<int>
     */
    public static function retryableValues(): array
    {
        return [
            self::Unsearched->value,
            self::RetryAfterFirstMiss->value,
            self::RetryAfterSecondMiss->value,
            self::RetryAfterThirdMiss->value,
        ];
    }

    /**
     * @return list<int>
     */
    public static function rearmableValues(): array
    {
        return [...self::retryableValues(), self::Parked->value];
    }
}
