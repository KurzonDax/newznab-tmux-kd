<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Models\Settings;

final class ReconciliationLimits
{
    public const int MIB = 1048576;

    public static function maximumMib(): int
    {
        return intdiv(PHP_INT_MAX, self::MIB);
    }

    public function hourBytes(): int
    {
        return $this->bytes('reconciliation_hourly_mib', (int) config('collection-reconciliation.hour_bytes', 268435456));
    }

    public function dayBytes(): int
    {
        return $this->bytes('reconciliation_daily_mib', (int) config('collection-reconciliation.day_bytes', 2147483648));
    }

    private function bytes(string $key, int $fallback): int
    {
        $value = Settings::settingValue($key);
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1, 'max_range' => self::maximumMib(),
        ]]);

        return is_int($number) ? $number * self::MIB : $fallback;
    }
}
