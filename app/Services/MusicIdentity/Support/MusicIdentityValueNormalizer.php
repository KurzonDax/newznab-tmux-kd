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

    /**
     * Accepts the display form (`US-RC1-76-07839`) and returns the 12-character ISRC, or null when invalid.
     */
    public static function isrc(?string $value): ?string
    {
        $value = self::text($value === null ? null : str_replace(['-', ' '], '', $value), uppercase: true);

        return $value !== null && preg_match('/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/D', $value) === 1 ? $value : null;
    }

    /**
     * Returns a 28-character MusicBrainz Disc ID, or null for anything else (such as an 8-character CDDB ID).
     */
    public static function discId(?string $value): ?string
    {
        $value = self::text($value);

        return $value !== null && preg_match('/^[A-Za-z0-9._-]{28}$/D', $value) === 1 ? $value : null;
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
