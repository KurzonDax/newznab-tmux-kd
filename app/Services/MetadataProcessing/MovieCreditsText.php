<?php

declare(strict_types=1);

namespace App\Services\MetadataProcessing;

/**
 * The one rule that reads a film's comma-joined `genre`, `director` or `actors` text as a
 * list of names: control characters become spaces, the text splits on commas, each part is
 * trimmed and empty parts are dropped, and a part that is only a name suffix joins the name
 * before it ("Robert Downey, Jr." is "Robert Downey Jr.", as TMDB spells it). Nothing else is
 * filtered: a genre cut short by the old 64-character column is kept as written.
 */
final class MovieCreditsText
{
    private const string SUFFIX = '/^(?:Jr|Sr|II|III|IV)\.?$/';

    /**
     * @return list<string>
     */
    public static function names(string $text): array
    {
        $text = preg_replace('/\p{Cc}/u', ' ', $text) ?? preg_replace('/[\x00-\x1F\x7F]/', ' ', $text) ?? $text;

        $names = [];
        foreach (explode(',', $text) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if ($names !== [] && preg_match(self::SUFFIX, $part) === 1) {
                $names[array_key_last($names)] .= ' '.$part;

                continue;
            }
            $names[] = $part;
        }

        return $names;
    }

    /**
     * The names as people with no TMDB id, for {@see MovieCredits::sync()}.
     *
     * @return list<array{name: string, tmdb_id: null}>
     */
    public static function people(string $text): array
    {
        return array_map(static fn (string $name): array => ['name' => $name, 'tmdb_id' => null], self::names($text));
    }
}
