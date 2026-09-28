<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\Request;

/**
 * The film bar (docs/proposals/movies-redesign/SPEC.md 5.2): Genre, Year, Score, MPAA Rating and
 * Language describe the film, so a release with no matched film is left out while any is set.
 * Values live in the URL; menus combine with AND and values inside a menu with OR. Year is
 * either ticked decades or a range (From alone is one year), never both.
 */
final readonly class MovieFilmFilters
{
    /** The earliest year the Year menu offers and accepts. */
    public const int FIRST_YEAR = 1900;

    /** A film with fewer TMDB votes than this, or no score, is in "Too few votes". */
    public const int MIN_VOTES = 10;

    /** The Score menu: URL value => item, in menu order; the numbers are each band's lowest score. */
    public const SCORES = ['9' => '9+', '8' => '8–8.9', '7' => '7–7.9', '6' => '6–6.9', '5' => '5–5.9', 'low' => 'Under 5', 'few' => 'Too few votes'];

    /** The MPAA Rating menu's order; it lists only the ratings present. */
    public const RATINGS = ['G', 'PG', 'PG-13', 'R', 'NC-17', 'NR'];

    /**
     * @param  list<int>  $genres  ticked genres.id (type 2000), in menu order
     * @param  list<int>  $decades  ticked decades (1990 for the 1990s), newest first
     * @param  int|null  $yearFrom  the range's first year; with it, $decades is empty
     * @param  int|null  $yearTo  the range's last year; null reads From alone as one year
     * @param  list<string>  $scores  ticked keys of SCORES, in menu order
     * @param  list<string>  $ratings  ticked MPAA ratings, in menu order
     * @param  list<string>  $languages  ticked Language values (an original_language code per name), in menu order
     * @param  list<string>  $languageCodes  every original_language code the ticked names stand for
     */
    public function __construct(
        public array $genres = [],
        public array $decades = [],
        public ?int $yearFrom = null,
        public ?int $yearTo = null,
        public array $scores = [],
        public array $ratings = [],
        public array $languages = [],
        public array $languageCodes = [],
    ) {}

    /**
     * Values outside the menus, and a range outside FIRST_YEAR to the current year or with its
     * later year first, are ignored; a range replaces ticked decades.
     *
     * @param  array{genre: array<int, string>, rating: array<string, string>, language: array<string, string>, language_codes: array<string, list<string>>}  $options
     */
    public static function fromRequest(Request $request, array $options): self
    {
        $ticked = static fn (string $key, array $values): array => array_values(array_intersect(
            array_map('strval', $values),
            array_map('strval', array_filter((array) $request->query($key, []), 'is_scalar')),
        ));
        $languages = $ticked('language', array_keys($options['language']));
        $codes = [];
        foreach ($languages as $language) {
            $codes = [...$codes, ...($options['language_codes'][$language] ?? [$language])];
        }
        [$from, $to] = self::range($request->query('year_from'), $request->query('year_to'));

        return new self(
            genres: array_map('intval', $ticked('genre', array_keys($options['genre']))),
            decades: $from === null ? array_map('intval', $ticked('decade', array_keys(self::decadeOptions()))) : [],
            yearFrom: $from,
            yearTo: $to,
            scores: $ticked('score', array_keys(self::SCORES)),
            ratings: $ticked('rating', array_keys($options['rating'])),
            languages: $languages,
            languageCodes: $codes,
        );
    }

    /**
     * A valid Year range as [from, to]: four-digit years from FIRST_YEAR to the current year,
     * the earlier first; To may be left empty (From alone). Anything else is [null, null].
     *
     * @return array{?int, ?int}
     */
    public static function range(mixed $from, mixed $to): array
    {
        $year = static fn (mixed $value): ?int => is_string($value) && preg_match('/^\d{4}$/', $value) === 1
            && (int) $value >= self::FIRST_YEAR && (int) $value <= self::lastYear() ? (int) $value : null;
        $first = $year($from);
        if ($first === null) {
            return [null, null];
        }
        if ($to === null || $to === '') {
            return [$first, null];
        }
        $last = $year($to);

        return $last === null || $last < $first ? [null, null] : [$first, $last];
    }

    /** The latest year the Year menu accepts: the current year. */
    public static function lastYear(): int
    {
        return (int) now()->format('Y');
    }

    /** @return array<int, string> the Year menu's decades, newest first: 2020 => "2020s" … 1900 => "1900s" */
    public static function decadeOptions(): array
    {
        $decades = [];
        for ($decade = intdiv(self::lastYear(), 10) * 10; $decade >= self::FIRST_YEAR; $decade -= 10) {
            $decades[$decade] = $decade.'s';
        }

        return $decades;
    }

    /** Whether a Year value is set (decades or a range). */
    public function anyYear(): bool
    {
        return $this->decades !== [] || $this->yearFrom !== null;
    }

    /** The Year range in words: "1980–1989", or "2024" for From alone; '' without a range. */
    public function rangeText(): string
    {
        return self::rangeLabel($this->yearFrom, $this->yearTo);
    }

    /** A Year range in words: "1980–1989", or "2024" for one year; '' without a first year. */
    public static function rangeLabel(?int $from, ?int $to): string
    {
        return match (true) {
            $from === null => '',
            $to === null || $to === $from => (string) $from,
            default => $from.'–'.$to,
        };
    }

    /** @return array{0: int, 1: int}|null the range's first and last year */
    public function yearBounds(): ?array
    {
        return $this->yearFrom === null ? null : [$this->yearFrom, $this->yearTo ?? $this->yearFrom];
    }

    /** @return list<string> the original_language codes the ticked languages match */
    public function languageValues(): array
    {
        return $this->languageCodes === [] ? $this->languages : $this->languageCodes;
    }

    public function any(): bool
    {
        return $this->genres !== [] || $this->anyYear() || $this->scores !== [] || $this->ratings !== [] || $this->languages !== [];
    }

    /**
     * What is set, for the empty result, as the prototype's filterText(): "Horror or Drama",
     * "in French", "1990s or 2000s", "score 9+ or too few votes", "rated R or PG-13".
     *
     * Keyed by menu (genre, language, year, score, rating) in the list's order; the Films wall
     * puts them in its own order. Unset menus are left out.
     *
     * @param  array<int, string>  $genres  the Genre menu
     * @param  array<string, string>  $languages  the Language menu
     * @return array<string, string>
     */
    public function describe(array $genres, array $languages): array
    {
        $name = static fn (int|string $value, array $names): string => (string) ($names[$value] ?? $value);

        return array_filter([
            'genre' => implode(' or ', array_map(static fn (int $genre): string => $name($genre, $genres), $this->genres)),
            'language' => $this->languages === [] ? '' : 'in '.implode(' or ', array_map(static fn (string $language): string => $name($language, $languages), $this->languages)),
            'year' => $this->yearFrom !== null ? $this->rangeText() : implode(' or ', array_map(static fn (int $decade): string => $decade.'s', $this->decades)),
            'score' => implode(' or ', array_map(static fn (string $score): string => $score === 'few' ? 'too few votes' : 'score '.self::SCORES[$score], $this->scores)),
            'rating' => $this->ratings === [] ? '' : 'rated '.implode(' or ', $this->ratings),
        ], static fn (string $part): bool => $part !== '');
    }

    /** @return array<string, list<int|string>|int> the URL query */
    public function query(): array
    {
        return array_filter([
            'genre' => $this->genres, 'decade' => $this->decades, 'year_from' => $this->yearFrom ?? [], 'year_to' => $this->yearTo ?? [],
            'score' => $this->scores, 'rating' => $this->ratings, 'language' => $this->languages,
        ], static fn (array|int $value): bool => $value !== []);
    }

    /** @return list<mixed> */
    public function countKey(): array
    {
        return [$this->genres, $this->decades, $this->yearBounds(), $this->scores, $this->ratings, $this->languageValues()];
    }
}
