<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Names a language from a media-info value (`en`, `pt-BR`, `English (US)`) or a TMDB
 * `original_language` code, for the Audio and Language menus (Movies SPEC 5.2). One
 * structural rule, no junk filtering: the base code or name before any region names the
 * language, codes that are not a language are dropped, and a value not in the table is
 * kept as written.
 */
final class LanguageNames
{
    /** Width of `languages.name`. */
    public const int NAME_LENGTH = 64;

    /** Lower-case base code or name => language name. */
    private const array NAMES = [
        'ar' => 'Arabic', 'bg' => 'Bulgarian', 'bn' => 'Bengali', 'br' => 'Breton', 'ca' => 'Catalan',
        'cmn' => 'Chinese', 'mandarin' => 'Chinese', 'cn' => 'Cantonese', 'cs' => 'Czech', 'da' => 'Danish',
        'de' => 'German', 'el' => 'Greek', 'en' => 'English', 'es' => 'Spanish', 'fi' => 'Finnish',
        'fil' => 'Filipino', 'fr' => 'French', 'he' => 'Hebrew', 'hi' => 'Hindi', 'hr' => 'Croatian',
        'hu' => 'Hungarian', 'id' => 'Indonesian', 'it' => 'Italian', 'ja' => 'Japanese', 'kn' => 'Kannada',
        'ko' => 'Korean', 'lv' => 'Latvian', 'ml' => 'Malayalam', 'ms' => 'Malay', 'nb' => 'Norwegian',
        'nl' => 'Dutch', 'no' => 'Norwegian', 'pl' => 'Polish', 'pt' => 'Portuguese', 'ro' => 'Romanian',
        'ru' => 'Russian', 'sk' => 'Slovak', 'sl' => 'Slovenian', 'sr' => 'Serbian', 'sv' => 'Swedish',
        'ta' => 'Tamil', 'te' => 'Telugu', 'th' => 'Thai', 'tr' => 'Turkish', 'uk' => 'Ukrainian',
        'vi' => 'Vietnamese', 'yue' => 'Cantonese', 'zh' => 'Chinese', 'eng' => 'English', 'fre' => 'French',
        'fra' => 'French', 'ger' => 'German', 'deu' => 'German', 'spa' => 'Spanish', 'ita' => 'Italian',
        'jpn' => 'Japanese', 'kor' => 'Korean', 'hin' => 'Hindi', 'por' => 'Portuguese', 'rus' => 'Russian',
        'chi' => 'Chinese', 'zho' => 'Chinese',
    ];

    /**
     * Not a language: the standard's codes (zxx no speech, mul multiple, und undetermined,
     * qaa-qtz local use) and the spelled-out "no language" values media info writes.
     */
    private const string NOT_A_LANGUAGE = '/^(zxx|mul|und|q[a-t][a-z]|unknown|unknown language|unk|none)$/i';

    /** The language a value names, or null when it names none. */
    public static function name(?string $value): ?string
    {
        $base = trim(preg_split('/[-_]| \(/', trim((string) $value), 2)[0]);
        if ($base === '' || preg_match(self::NOT_A_LANGUAGE, $base) === 1) {
            return null;
        }

        return rtrim(mb_substr(self::NAMES[mb_strtolower($base)] ?? $base, 0, self::NAME_LENGTH));
    }

    /**
     * The distinct languages the values name, in first-seen order.
     *
     * @param  iterable<?string>  $values
     * @return list<string>
     */
    public static function distinct(iterable $values): array
    {
        $names = [];
        foreach ($values as $value) {
            $name = self::name($value);
            if ($name !== null && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
