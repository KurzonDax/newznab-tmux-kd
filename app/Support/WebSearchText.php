<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Query\Builder;

/** The relational fallback for web searches; indexed release retrieval keeps its native parser. */
final class WebSearchText
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $excludedColumns  Additional columns that must honor excluded terms.
     */
    public static function apply(Builder $query, array $columns, string $text, array $excludedColumns = []): void
    {
        preg_match_all('/[!-]?"[^"]*"|[!-]?\(|\)|\||[^\s()|]+/u', $text, $matches);
        $tokens = $matches[0];
        $position = 0;
        [$sql, $bindings] = self::expression($tokens, $position, $columns, $excludedColumns);
        if ($sql !== '') {
            $query->whereRaw($sql, $bindings);
        }
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $columns
     * @param  list<string>  $excludedColumns
     * @return array{string, list<string>}
     */
    private static function expression(array $tokens, int &$position, array $columns, array $excludedColumns): array
    {
        $alternatives = [];
        $required = [];
        $bindings = [];
        while (isset($tokens[$position])) {
            $token = $tokens[$position++];
            if ($token === ')') {
                break;
            }
            if ($token === '|') {
                if ($required !== []) {
                    $alternatives[] = '('.implode(' AND ', $required).')';
                    $required = [];
                }

                continue;
            }
            $negative = str_starts_with($token, '-') || str_starts_with($token, '!');
            if ($negative || str_starts_with($token, '+')) {
                $token = substr($token, 1);
            }
            if ($token === '(') {
                [$sql, $values] = self::expression($tokens, $position, $negative ? array_merge($columns, $excludedColumns) : $columns, $excludedColumns);
            } else {
                $token = trim($token, '"');
                if ($token === '') {
                    continue;
                }
                $normalized = str_replace(['.', '_', '-'], ' ', mb_strtolower($token));
                $pattern = '%'.str_replace(['!', '%', '_', '*'], ['!!', '!%', '!_', '%'], $normalized).'%';
                $matchedColumns = $negative ? array_merge($columns, $excludedColumns) : $columns;
                $sql = implode(' OR ', array_map(static fn (string $column): string => "LOWER(REPLACE(REPLACE(REPLACE(COALESCE($column, ''), '.', ' '), '_', ' '), '-', ' ')) LIKE ? ESCAPE '!'", $matchedColumns));
                $values = array_fill(0, count($matchedColumns), $pattern);
            }
            if ($sql !== '') {
                $required[] = ($negative ? 'NOT ' : '').'('.$sql.')';
                array_push($bindings, ...$values);
            }
        }
        if ($required !== []) {
            $alternatives[] = '('.implode(' AND ', $required).')';
        }

        return [implode(' OR ', $alternatives), $bindings];
    }
}
