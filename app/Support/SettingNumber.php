<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Settings;

/**
 * Reads a numeric site setting, falling back when the row says nothing usable.
 *
 * Settings rows are free-text: a row can be missing on an install that predates it, blank
 * because someone cleared the field, or non-numeric because someone typed into the wrong box.
 * None of those should change how a scheduled job behaves, so each one falls back to the
 * constant the setting replaced.
 */
final class SettingNumber
{
    public static function float(string $name, float $fallback): float
    {
        $value = Settings::settingValue($name);

        return is_numeric($value) ? (float) $value : $fallback;
    }

    public static function int(string $name, int $fallback): int
    {
        $value = Settings::settingValue($name);

        return is_numeric($value) ? (int) $value : $fallback;
    }
}
