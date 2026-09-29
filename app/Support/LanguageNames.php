<?php

declare(strict_types=1);

namespace App\Support;

use Locale;
use Normalizer;
use ResourceBundle;

/**
 * Names a language from a media-info value (`en`, `pt-BR`, `English (US)`) or a TMDB
 * `original_language` code, for the Audio and Language menus (Movies SPEC 5.2). A value
 * we cannot identify as a language names none, so a release whose audio values all name
 * none is Unknown. The value is cleaned (trimmed, surrounding quotes stripped, the part
 * before " / " kept) and its region dropped; values that are not a language name none.
 * The rest is identified, case- and accent-insensitively, as a code `intl` can name, a
 * language's English name from `intl` or the ISO 639-2 list ({@see Iso6392}), a
 * language's own name from `intl`, or an entry in the table. The identified code is
 * reduced to its ISO 639-1 code when it has one and named by the table, else by `intl`
 * in English. Anything else names no language.
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
        'chi' => 'Chinese', 'zho' => 'Chinese', 'हिंदी' => 'Hindi',
    ];

    /**
     * Not a language: the standard's codes (zxx no speech, mul multiple, und undetermined,
     * qaa-qtz local use) and the spelled-out "no language" values media info writes.
     */
    private const string NOT_A_LANGUAGE = '/^('.self::NOT_A_LANGUAGE_CODES.'|unknown|unknown language|unk|none|multiple languages)$/i';

    /** The standard's not-a-language codes, also dropped however a value was identified as one. */
    private const string NOT_A_LANGUAGE_CODES = 'zxx|mul|und|q[a-t][a-z]';

    /** @var array{names: array<string, string>, twoLetter: array<string, string>}|null */
    private static ?array $index = null;

    /** The language a value names, or null when it names none. */
    public static function name(?string $value): ?string
    {
        $cleaned = explode(' / ', trim(trim(trim((string) $value), '\'"')), 2)[0];
        $base = trim(preg_split('/[-_]| \(/', $cleaned, 2)[0]);
        if ($base === '' || preg_match(self::NOT_A_LANGUAGE, $base) === 1) {
            return null;
        }

        $code = self::identify($base);
        if ($code === null || preg_match('/^('.self::NOT_A_LANGUAGE_CODES.')$/', $code) === 1) {
            return null;
        }
        $code = self::index()['twoLetter'][$code] ?? $code;

        return rtrim(mb_substr(self::NAMES[$code] ?? self::english($code), 0, self::NAME_LENGTH));
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

    /** The table key or code the base value identifies, or null. */
    private static function identify(string $base): ?string
    {
        $lower = mb_strtolower($base);
        if (array_key_exists($lower, self::NAMES) || self::nameable($lower)) {
            return $lower;
        }

        return self::index()['names'][self::fold($base)] ?? null;
    }

    /**
     * Folded name => code for every English name (`intl`, then the ISO 639-2 list) and own
     * name (`intl`) of a code `intl` can name, and three-letter code => ISO 639-1 code. The
     * codes tried are the ISO 639-2 list's, the languages `intl` has locale data, English
     * names or aliases for.
     *
     * @return array{names: array<string, string>, twoLetter: array<string, string>}
     */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $rows = array_map(static fn (string $row): array => explode('|', $row), Iso6392::ROWS);
        $twoLetter = [];
        $codes = [];
        foreach ($rows as [$bibliographic, $terminologic, $alpha2]) {
            foreach ([$bibliographic, $terminologic] as $code) {
                if ($code !== '' && $alpha2 !== '') {
                    $twoLetter[$code] = $alpha2;
                }
            }
            array_push($codes, $alpha2, $bibliographic, $terminologic);
        }
        $ownNamed = [];
        foreach (ResourceBundle::getLocales('') ?: [] as $locale) {
            $ownNamed[] = (string) Locale::getPrimaryLanguage($locale);
        }
        $codes = array_values(array_filter(
            array_unique([...$codes, ...$ownNamed, ...self::bundleKeys('en', 'ICUDATA-lang', 'Languages'), ...self::bundleKeys('metadata', 'ICUDATA', 'alias', 'language')]),
            self::nameable(...),
        ));

        $names = [];
        $add = static function (string $name, string $code) use (&$names): void {
            $key = self::fold($name);
            if ($key !== '' && ! isset($names[$key])) {
                $names[$key] = $code;
            }
        };
        foreach ($codes as $code) {
            $add(self::english($code), $code);
        }
        foreach ($rows as [$bibliographic, $terminologic, $alpha2, $english]) {
            $code = array_values(array_filter([$alpha2, $terminologic, $bibliographic], self::nameable(...)))[0] ?? null;
            if ($code !== null) {
                foreach (explode('; ', $english) as $name) {
                    $add($name, $code);
                }
            }
        }
        foreach (array_intersect(array_unique($ownNamed), $codes) as $code) {
            $add((string) Locale::getDisplayLanguage($code, $code), $code);
        }

        return self::$index = ['names' => $names, 'twoLetter' => $twoLetter];
    }

    /**
     * The keys of an `intl` resource bundle table, empty when the bundle is missing.
     *
     * @return list<string>
     */
    private static function bundleKeys(string $locale, string $bundle, string ...$path): array
    {
        $table = ResourceBundle::create($locale, $bundle);
        foreach ($path as $key) {
            $table = $table instanceof ResourceBundle ? $table->get($key) : null;
        }
        $keys = [];
        if ($table instanceof ResourceBundle) {
            foreach ($table as $key => $unused) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /** Whether the value is two or three ASCII letters that `intl` names as a language. */
    private static function nameable(string $code): bool
    {
        return preg_match('/^[a-z]{2,3}$/', $code) === 1 && strcasecmp(self::english($code), $code) !== 0;
    }

    /** `intl`'s English name for the code (the code itself when it has none). */
    private static function english(string $code): string
    {
        return (string) Locale::getDisplayLanguage($code, 'en');
    }

    /** The name folded for matching: lower case, accents dropped from Latin letters. */
    private static function fold(string $name): string
    {
        $decomposed = Normalizer::normalize($name, Normalizer::FORM_D);
        $bare = (string) preg_replace('/(?<=[A-Za-z])\p{Mn}+/u', '', is_string($decomposed) ? $decomposed : $name);

        return mb_strtolower(trim((string) Normalizer::normalize($bare, Normalizer::FORM_C)));
    }
}
