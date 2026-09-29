<?php

declare(strict_types=1);

namespace App\Services\MetadataProcessing;

/**
 * The one rule that reads a film's comma-joined `genre`, `director` or `actors` text as a
 * list of names: control characters become spaces, the text splits on commas, each part is
 * trimmed and empty parts are dropped, and a part that is only a name suffix joins the name
 * before it ("Robert Downey, Jr." is "Robert Downey Jr.", as TMDB spells it).
 *
 * The saved text can be cut short at its column's length, so two lists are read further.
 * A genre is one of TMDB's nineteen movie genres, matched ignoring case and given TMDB's
 * spelling; any other name ("Science F", "Science", "TV") is not a genre. A director text
 * that has reached 64 characters, where the column cuts it, loses its last comma-separated
 * part, which may be cut off. Cast names are all kept.
 */
final class MovieCreditsText
{
    private const string SUFFIX = '/^(?:Jr|Sr|II|III|IV)\.?$/';

    /**
     * TMDB's nineteen movie genres, by name.
     */
    private const array TMDB_MOVIE_GENRES = [
        'Action', 'Adventure', 'Animation', 'Comedy', 'Crime', 'Documentary', 'Drama', 'Family',
        'Fantasy', 'History', 'Horror', 'Music', 'Mystery', 'Romance', 'Science Fiction',
        'TV Movie', 'Thriller', 'War', 'Western',
    ];

    /** The length, in characters, at which `movieinfo.director` cuts a director text. */
    private const int DIRECTOR_TEXT_LENGTH = 64;

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
     * The names that are TMDB movie genres, in TMDB's spelling.
     *
     * @return list<string>
     */
    public static function genres(string $text): array
    {
        $genres = [];
        foreach (self::TMDB_MOVIE_GENRES as $genre) {
            $genres[mb_strtolower($genre)] = $genre;
        }

        $kept = [];
        foreach (self::names($text) as $name) {
            $genre = $genres[mb_strtolower($name)] ?? null;
            if ($genre !== null) {
                $kept[] = $genre;
            }
        }

        return $kept;
    }

    /**
     * The directors as people, without the last one when the text has reached the length
     * at which the column cuts it. A text cut just after a comma has an empty last part,
     * so it loses no name; a last part that is a name suffix goes with the name it joins.
     *
     * @return list<array{name: string, tmdb_id: null}>
     */
    public static function directors(string $text): array
    {
        $people = self::people($text);
        if (mb_strlen($text) >= self::DIRECTOR_TEXT_LENGTH && preg_match('/,[\s\p{Cc}]*$/u', $text) !== 1) {
            array_pop($people);
        }

        return $people;
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
