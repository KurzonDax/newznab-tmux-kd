<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/** Reads the deliberately limited SQL format emitted by Laravel's MariaDB schema:dump. */
final class SchemaAuthority
{
    private string $sql;

    public function __construct(private readonly string $path)
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Cannot read schema authority: '.$path);
        }
        $this->sql = $sql;
    }

    /**
     * Column definitions carry the base MariaDB type name, the raw DEFAULT literal and the
     * raw generation expression; nullability, charsets, comments and checks are not read.
     *
     * @return array<string, array{columns: list<string>, definitions: array<string, array{type: string, default: ?string, generated: ?string}>, primary: list<string>, uniques: list<list<string>>, autoIncrement: ?string}>
     */
    public function tables(): array
    {
        preg_match_all('/^CREATE TABLE `([^`]+)` \(\n(.*?)\n\) ENGINE=[^\n]+;/ms', $this->sql, $matches, PREG_SET_ORDER);
        $expected = preg_match_all('/^\s*CREATE\s+(?:TEMPORARY\s+)?TABLE\b/im', $this->sql);
        if ($expected === 0 || count($matches) !== $expected) {
            throw new RuntimeException("{$this->path}: unsupported CREATE TABLE definition; authority must parse every table.");
        }

        $tables = [];
        foreach ($matches as $match) {
            $table = ['columns' => [], 'definitions' => [], 'primary' => [], 'uniques' => [], 'autoIncrement' => null];
            foreach (explode("\n", $match[2]) as $line) {
                $line = trim($line);
                if (preg_match('/^`([^`]+)`\s+([a-z]+)/i', $line, $column)) {
                    $table['columns'][] = $column[1];
                    // Comments/default strings are not SQL keywords; same-length masking keeps offsets.
                    $definition = preg_replace_callback("/'(?:[^'\\\\]|\\\\.|'')*'/", static fn (array $quoted): string => "'".str_repeat('x', strlen($quoted[0]) - 2)."'", $line);
                    $table['definitions'][$column[1]] = ['type' => strtolower($column[2]), ...$this->valueSource($line, $definition, "{$match[1]}.{$column[1]}")];
                    if (preg_match('/\bAUTO_INCREMENT\b/i', $definition)) {
                        if ($table['autoIncrement'] !== null) {
                            throw new RuntimeException("{$match[1]}: multiple auto-increment columns");
                        }
                        $table['autoIncrement'] = $column[1];
                    }
                } elseif (preg_match('/^PRIMARY KEY \((.*)\),?$/', $line, $key)) {
                    $table['primary'] = $this->keyColumns($key[1], $match[1]);
                } elseif (preg_match('/^UNIQUE KEY `[^`]+` \((.*)\)(?: USING \w+)?,?$/', $line, $key)) {
                    $table['uniques'][] = $this->keyColumns($key[1], $match[1]);
                } elseif (! preg_match('/^(?:(?:FULLTEXT |SPATIAL )?KEY `|CONSTRAINT `[^`]+` (?:FOREIGN KEY|CHECK)\s*\(|CHECK\s*\()/i', $line)) {
                    throw new RuntimeException("{$this->path}: unsupported definition in {$match[1]}: {$line}");
                }
            }
            if ($table['columns'] === [] || isset($tables[$match[1]])) {
                throw new RuntimeException("{$this->path}: empty or duplicate table {$match[1]}");
            }
            $tables[$match[1]] = $table;
        }

        return $tables;
    }

    /** @param list<string> $migrationPaths */
    public function assertFresh(array $migrationPaths): void
    {
        preg_match_all('/^INSERT INTO `migrations`[^\n]* VALUES (.*);$/m', $this->sql, $inserts);
        preg_match_all("/\\(\\d+,\\s*'([^']+)',\\s*\\d+\\)/", implode("\n", $inserts[1]), $rows);
        if ($rows[1] === []) {
            throw new RuntimeException($this->path.': cannot read migrations rows; refresh with artisan schema:dump.');
        }
        $missing = array_diff(array_map(static fn (string $path): string => basename($path, '.php'), $migrationPaths), $rows[1]);
        if ($missing !== []) {
            sort($missing);
            throw new RuntimeException($this->path.': unrecorded migrations: '.implode(', ', $missing).'; refresh the fully migrated MariaDB dump with artisan schema:dump.');
        }
    }

    /**
     * @param  string  $masked  $line with quoted strings masked at the same length
     * @return array{default: ?string, generated: ?string}
     */
    private function valueSource(string $line, string $masked, string $column): array
    {
        if (preg_match('/\bGENERATED ALWAYS AS \(/i', $masked, $generated, PREG_OFFSET_CAPTURE)) {
            $start = $generated[0][1] + strlen($generated[0][0]);
            for ($end = $start, $depth = 1; $end < strlen($masked); $end++) {
                $depth += match ($masked[$end]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
                if ($depth === 0) {
                    return ['default' => null, 'generated' => substr($line, $start, $end - $start)];
                }
            }
            throw new RuntimeException("{$this->path}: unbalanced generation expression for {$column}");
        }
        if (preg_match('/\bDEFAULT\s+/i', $masked, $default, PREG_OFFSET_CAPTURE)) {
            $start = $default[0][1] + strlen($default[0][0]);
            if (! preg_match("/\\G(?:'[^']*'|[^\\s,]+)/", $masked, $literal, 0, $start)) {
                throw new RuntimeException("{$this->path}: unsupported default for {$column}");
            }

            return ['default' => substr($line, $start, strlen($literal[0])), 'generated' => null];
        }

        return ['default' => null, 'generated' => null];
    }

    /** @return list<string> */
    private function keyColumns(string $definition, string $table): array
    {
        $columns = [];
        foreach (explode(',', $definition) as $part) {
            if (! preg_match('/^`([^`]+)`(?:\(\d+\))?(?: (?:ASC|DESC))?$/', trim($part), $match)) {
                throw new RuntimeException("{$this->path}: unsupported key in {$table}: {$definition}");
            }
            $columns[] = $match[1];
        }
        sort($columns);

        return $columns;
    }
}
