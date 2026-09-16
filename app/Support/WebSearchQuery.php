<?php

declare(strict_types=1);

namespace App\Support;

/** Web-only terms retain quotes and Boolean groups through chips and redirects. */
final readonly class WebSearchQuery
{
    /** @param array<string, string> $terms */
    private function __construct(private array $terms, private string $text) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $registry = app(WebSearchFields::class);
        $terms = [];
        $free = [];
        $query = is_scalar($input['q'] ?? null) ? trim((string) $input['q']) : '';
        preg_match_all('/(?:[a-z][a-z0-9_]*:)?(?:[!-]?"[^"]*"|[!-]?(\((?:[^()]|(?1))*\))|[^\s]+)/iu', $query, $matches);
        foreach ($matches[0] as $token) {
            if (preg_match('/^([a-z][a-z0-9_]*):(.*)$/isu', $token, $field) && ($prefix = $registry->canonical($field[1])) !== null) {
                $terms[$prefix] = trim(($terms[$prefix] ?? '').' '.$field[2]);
            } else {
                $free[] = $token;
            }
        }
        foreach ([...array_keys($registry->all()), 'actor'] as $prefix) {
            if (isset($input[$prefix]) && is_scalar($input[$prefix]) && trim((string) $input[$prefix]) !== '') {
                $key = $registry->canonical($prefix);
                $terms[$key] = trim(($terms[$key] ?? '').' '.(string) $input[$prefix]);
            }
        }

        return new self($terms, implode(' ', $free));
    }

    public function freeText(): string
    {
        return $this->text;
    }

    /** @return array<string, string> */
    public function indexTerms(): array
    {
        return array_filter(['all' => $this->text, ...$this->terms], static fn (string $value): bool => $value !== '');
    }
}
