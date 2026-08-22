<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Small UTF-8 conversion helper used in hot header-processing paths.
 */
final class Utf8
{
    /** @var list<string>|null */
    private static ?array $encodings = null;

    public static function clean(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', self::encodings());
    }

    public static function scrubFilename(string $value): string
    {
        $substituteCharacter = mb_substitute_character();
        mb_substitute_character('none');

        try {
            $value = mb_scrub($value, 'UTF-8');
        } finally {
            mb_substitute_character($substituteCharacter);
        }

        return trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '');
    }

    /**
     * @return list<string>
     */
    private static function encodings(): array
    {
        if (self::$encodings === null) {
            self::$encodings = mb_list_encodings();
        }

        return self::$encodings;
    }
}
