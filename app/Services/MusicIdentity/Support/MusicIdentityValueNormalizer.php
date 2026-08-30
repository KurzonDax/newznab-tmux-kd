<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Support;

final class MusicIdentityValueNormalizer
{
    public static function identifier(?string $value, bool $uppercase = false): ?string
    {
        $value = self::text($value);

        return $value === null ? null : ($uppercase ? strtoupper($value) : strtolower($value));
    }

    public static function musicBrainzId(?string $value): ?string
    {
        $value = self::text($value);

        return $value !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1
            ? strtolower($value)
            : null;
    }

    public static function text(?string $value, bool $uppercase = false): ?string
    {
        $value = $value === null ? null : trim($value);
        if ($value === null || $value === '') {
            return null;
        }

        return $uppercase ? strtoupper($value) : $value;
    }
}
