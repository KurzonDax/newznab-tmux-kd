<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;

final class FixtureSchemaGuard
{
    /** @var array<string, list<array{table: string, rule: string, subject: string, authority: string}>> */
    private array $cache = [];

    public int $inspections = 0;

    public int $validations = 0;

    /** @param array<string, array{columns: list<string>, primary: list<string>, uniques: list<list<string>>, autoIncrement: ?string}> $authority */
    public function __construct(private readonly array $authority) {}

    /**
     * Inspect the live database, including separately created indexes and temporary tables.
     *
     * @param  list<string>  $testOnlyTables
     * @return list<array{table: string, rule: string, subject: string, authority: string, database: string}>
     */
    public function inspect(PDO $pdo, array $testOnlyTables = []): array
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            return [];
        }
        $this->inspections++;
        $databases = array_unique(['main', 'temp', ...array_column($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC), 'name')]);
        $catalogs = [];
        foreach ($databases as $database) {
            $catalogs[] = 'SELECT '.$pdo->quote($database).' AS db, type, name, tbl_name, sql FROM '.self::identifier($database).".sqlite_master WHERE type IN ('table', 'index')";
        }
        $objects = $pdo->query(implode(' UNION ALL ', $catalogs).' ORDER BY db, type, name')->fetchAll(PDO::FETCH_ASSOC);
        $definitions = [];
        foreach ($objects as $object) {
            $database = $object['db'];
            unset($object['db']);
            $definitions[$database][$object['tbl_name']][] = $object;
        }
        $violations = [];
        foreach ($definitions as $database => $tables) {
            foreach ($tables as $name => $objects) {
                if (str_starts_with($name, 'sqlite_')) {
                    continue;
                }
                if (! isset($this->authority[$name])) {
                    if (! in_array($name, $testOnlyTables, true)) {
                        $violations[] = ['table' => $name, 'rule' => 'R4', 'subject' => $name, 'authority' => 'no production table; declare this table in fixtureOnlyTables()', 'database' => $database];
                    }

                    continue;
                }
                // Index DDL is part of the key: CREATE/DROP UNIQUE INDEX must invalidate it.
                $signature = hash('sha256', serialize($objects));
                if (! isset($this->cache[$signature])) {
                    $this->validations++;
                    $this->cache[$signature] = $this->validate($pdo, $database, $name);
                }
                foreach ($this->cache[$signature] as $violation) {
                    $violations[] = $violation + ['database' => $database];
                }
            }
        }

        return $violations;
    }

    /** @return list<array{table: string, rule: string, subject: string, authority: string}> */
    private function validate(PDO $pdo, string $database, string $table): array
    {
        $columns = [];
        $primary = [];
        foreach ($pdo->query('PRAGMA '.self::identifier($database).'.table_xinfo('.$pdo->quote($table).')')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[] = $column['name'];
            if ($column['pk']) {
                $primary[] = $column['name'];
            }
        }
        sort($primary);
        $keys = $primary === [] ? [] : [['columns' => $primary, 'partial' => false]];
        foreach ($pdo->query('PRAGMA '.self::identifier($database).'.index_list('.$pdo->quote($table).')')->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if (! $index['unique'] || $index['origin'] === 'pk') {
                continue;
            }
            $parts = $pdo->query('PRAGMA '.self::identifier($database).'.index_info('.$pdo->quote($index['name']).')')->fetchAll(PDO::FETCH_ASSOC);
            // An expression is deliberately not mistaken for a real column key.
            $names = array_map(static fn (array $part): string => $part['name'] ?? '<expression>', $parts);
            sort($names);
            $keys[] = ['columns' => $names, 'partial' => (bool) $index['partial']];
        }
        $definition = $this->authority[$table];
        $realKeys = $definition['uniques'];
        if ($definition['primary'] !== []) {
            $realKeys[] = $definition['primary'];
        }
        if ($definition['autoIncrement'] !== null) {
            $realKeys[] = [$definition['autoIncrement']];
        }
        $realKeys = array_values(array_unique($realKeys, SORT_REGULAR));
        $description = 'columns ('.implode(', ', $definition['columns']).'); keys '.implode(', ', array_map(self::keyLabel(...), $realKeys));
        $violations = [];
        $add = static function (string $rule, string $subject) use (&$violations, $table, $description): void {
            $violations[] = ['table' => $table, 'rule' => $rule, 'subject' => $subject, 'authority' => $description];
        };
        foreach (array_diff($columns, $definition['columns']) as $column) {
            $add('R1', $column);
        }
        foreach ($realKeys as $key) {
            if (array_diff($key, $columns) === [] && ! $this->containsSubset(array_column(array_filter($keys, static fn (array $key): bool => ! $key['partial']), 'columns'), $key)) {
                $add('R2', self::keyLabel($key));
            }
        }
        foreach ($keys as $key) {
            if (! $this->containsSubset($realKeys, $key['columns'])) {
                $add('R3', self::keyLabel($key['columns']));
            }
        }

        return $violations;
    }

    /** @param list<list<string>> $keys
     * @param  list<string>  $superset
     */
    private function containsSubset(array $keys, array $superset): bool
    {
        foreach ($keys as $key) {
            if (array_diff($key, $superset) === []) {
                return true;
            }
        }

        return false;
    }

    private static function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    /** @param list<string> $columns */
    private static function keyLabel(array $columns): string
    {
        return '('.implode(', ', $columns).')';
    }
}
