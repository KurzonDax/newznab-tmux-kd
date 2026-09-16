<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Container\Attributes\Singleton;

/** Prefix registration supplies both index lookup and its relational fallback. */
#[Singleton]
final class WebSearchFields
{
    /** @var array<string, array{index:string, field:string, table:string, key:string, attribute:string}> */
    private array $fields = [];

    public function __construct()
    {
        foreach (['title', 'actors', 'director', 'plot'] as $field) {
            $this->register($field, 'movies', $field, 'movieinfo', 'imdbid', 'imdbid');
        }
    }

    public function register(string $prefix, string $index, string $field, string $table, string $key, string $attribute): void
    {
        foreach ([$prefix, $index, $field, $table, $key, $attribute] as $identifier) {
            if (! preg_match('/^[a-z][a-z0-9_]*$/D', $identifier)) {
                throw new \InvalidArgumentException('Invalid search registration identifier');
            }
        }
        $this->fields[$prefix] = compact('index', 'field', 'table', 'key', 'attribute');
    }

    /** @return array<string, array{index:string, field:string, table:string, key:string, attribute:string}> */
    public function all(): array
    {
        return $this->fields;
    }

    public function canonical(string $prefix): ?string
    {
        $prefix = strtolower($prefix);
        $prefix = $prefix === 'actor' ? 'actors' : $prefix;

        return isset($this->fields[$prefix]) ? $prefix : null;
    }
}
