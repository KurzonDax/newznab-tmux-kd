<?php

declare(strict_types=1);

namespace App\Support;

final class MovieSearchQuery
{
    private const FIELD_ORDER = ['all', 'title', 'actors', 'director', 'plot'];

    /**
     * @param  array<string, list<string>>  $terms
     */
    private function __construct(private readonly array $terms) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $terms = array_fill_keys(self::FIELD_ORDER, []);
        $search = self::scalar($input['q'] ?? '');

        if ($search !== '') {
            preg_match_all(
                '/(?:(title|actors?|director|plot):(?:"([^"]*)"|(\S+)))|(?:"([^"]*)"|(\S+))/iu',
                $search,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $field = self::normalizeField((string) ($match[1] ?? '')) ?? 'all';
                $value = self::firstNonEmpty($match, [2, 3, 4, 5]);
                self::appendWords($terms[$field], $value);
            }
        }

        foreach (['title', 'actors', 'actor', 'director', 'plot'] as $field) {
            $value = self::scalar($input[$field] ?? '');
            $normalizedField = self::normalizeField($field);
            if ($value !== '' && $normalizedField !== null) {
                self::appendWords($terms[$normalizedField], $value);
            }
        }

        return new self($terms);
    }

    public function isEmpty(): bool
    {
        return $this->indexTerms() === [];
    }

    /**
     * @return array<string, string>
     */
    public function indexTerms(): array
    {
        $result = [];
        foreach (self::FIELD_ORDER as $field) {
            if ($this->terms[$field] !== []) {
                $result[$field] = implode(' ', $this->terms[$field]);
            }
        }

        return $result;
    }

    /**
     * @return array<string, list<string>>
     */
    public function termsByField(): array
    {
        return array_filter($this->terms, static fn (array $terms): bool => $terms !== []);
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? trim(stripslashes((string) $value)) : '';
    }

    private static function normalizeField(string $field): ?string
    {
        $field = strtolower($field);

        return match ($field) {
            'actor', 'actors' => 'actors',
            'title', 'director', 'plot' => $field,
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $matches
     * @param  list<int>  $indexes
     */
    private static function firstNonEmpty(array $matches, array $indexes): string
    {
        foreach ($indexes as $index) {
            if (($matches[$index] ?? '') !== '') {
                return $matches[$index];
            }
        }

        return '';
    }

    /**
     * @param  list<string>  $terms
     */
    private static function appendWords(array &$terms, string $value): void
    {
        $words = preg_split('/\s+/u', trim($value)) ?: [];
        $seen = array_fill_keys(array_map(static fn (string $term): string => mb_strtolower($term), $terms), true);

        foreach ($words as $word) {
            $word = trim($word, " \t\n\r\0\x0B\"");
            if ($word === '') {
                continue;
            }

            $normalized = mb_strtolower($word);
            if (isset($seen[$normalized])) {
                continue;
            }

            $terms[] = $word;
            $seen[$normalized] = true;
        }
    }
}
