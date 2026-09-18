<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;

final class FixtureSchemaGuard
{
    /** A quoted or bare identifier. */
    private const string IDENTIFIER = '(?:"(?:[^"]|"")*"|`(?:[^`]|``)*`|\[[^\]]*\]|[\w$]+)';

    /** An identifier, optionally qualified by its database. */
    private const string NAME = self::IDENTIFIER.'(?:\s*\.\s*'.self::IDENTIFIER.')?';

    /** @var array<string, list<array{table: string, rule: string, subject: string, authority: string}>> */
    private array $cache = [];

    public int $inspections = 0;

    public int $validations = 0;

    /** @param array<string, array{columns: list<string>, primary: list<string>, uniques: list<list<string>>, autoIncrement: ?string}> $authority */
    public function __construct(private readonly array $authority) {}

    /**
     * Every violation on the handle, including undeclared purpose-built tables.
     *
     * @param  list<string>  $testOnlyTables
     * @return list<array{table: string, rule: string, subject: string, authority: string, database: string}>
     */
    public function inspect(PDO $pdo, array $testOnlyTables = []): array
    {
        return array_values(array_filter(
            $this->examine($pdo)['violations'],
            static fn (array $violation): bool => $violation['rule'] !== 'R4' || ! in_array($violation['table'], $testOnlyTables, true),
        ));
    }

    /**
     * Inspect the live database: on SQLite every table, including separately created indexes,
     * temporary tables and attached databases; on MariaDB the schema's base and temporary tables.
     * Names are compared without the connection's table prefix, and a MariaDB connection with a
     * prefix owns only the tables carrying it.
     *
     * @param  list<string>|null  $only  Production names about to be destroyed; limits inspection and skips R4.
     * @param  string|null  $schema  The only SQLite database, or the MariaDB schema instead of the current one.
     * @return array{tables: list<string>, violations: list<array{table: string, rule: string, subject: string, authority: string, database: string}>}
     */
    public function examine(PDO $pdo, string $prefix = '', ?array $only = null, ?string $schema = null): array
    {
        $catalog = match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => $schema === null ? $this->sqliteCatalog($pdo) : array_intersect_key($this->sqliteCatalog($pdo), [$schema => true]),
            'mysql' => $this->mariaDbCatalog($pdo, $prefix, $schema),
            default => [],
        };
        if ($catalog === []) {
            return ['tables' => [], 'violations' => []];
        }
        $this->inspections++;
        $present = [];
        $violations = [];
        foreach ($catalog as $database => $tables) {
            foreach ($tables as $physical => [$signature, $shape]) {
                $name = self::logicalName($physical, $prefix);
                if ($only !== null && ! in_array($name, $only, true)) {
                    continue;
                }
                if (! isset($this->authority[$name])) {
                    if ($only === null) {
                        $violations[] = ['table' => $name, 'rule' => 'R4', 'subject' => $name, 'authority' => 'no production table; declare this table in fixtureOnlyTables()', 'database' => $database];
                    }

                    continue;
                }
                $present[] = $name;
                // Index DDL is part of the key: CREATE/DROP UNIQUE INDEX must invalidate it.
                $signature = hash('sha256', $name."\0".$signature);
                if (! isset($this->cache[$signature])) {
                    $this->validations++;
                    $this->cache[$signature] = $this->validate($name, $shape());
                }
                foreach ($this->cache[$signature] as $violation) {
                    $violations[] = $violation + ['database' => $database];
                }
            }
        }

        return ['tables' => array_values(array_unique($present)), 'violations' => $violations];
    }

    /**
     * What a statement does to tables: the ones it is about to destroy (a null table stands
     * for a whole database), the ones it creates, including a renamed table's new name, and
     * the ones it reshapes in place.
     *
     * @return array{destroyed: list<array{database: ?string, table: ?string}>, created: list<string>, reshaped: list<string>}
     */
    public static function schemaChanges(string $sql): array
    {
        $name = self::NAME;
        $renamed = 'rename\s+(?:to\s+|as\s+)?(?!column\b|index\b|key\b|constraint\b)';
        $patterns = [
            'drop' => "drop\s+(?:temporary\s+)?table\s+(?:if\s+exists\s+)?(?<names>{$name}(?:\s*,\s*{$name})*)",
            'create' => "create\s+(?<replace>or\s+replace\s+)?(?:temp(?:orary)?\s+)?table\s+(?:if\s+not\s+exists\s+)?(?<names>{$name})",
            'rename' => "alter\s+table\s+(?:if\s+exists\s+)?(?<names>{$name})\s+{$renamed}(?<to>{$name})",
            'renames' => "rename\s+tables?\s+(?<names>{$name}\s+to\s+{$name}(?:\s*,\s*{$name}\s+to\s+{$name})*)",
            'alter' => "alter\s+table\s+(?:if\s+exists\s+)?(?<names>{$name})",
            'index' => "(?:create\s+(?:unique\s+)?index\s+(?:if\s+not\s+exists\s+)?|drop\s+index\s+(?:if\s+exists\s+)?){$name}\s+on\s+(?<names>{$name})",
            'database' => "(?:drop\s+(?:database|schema)\s+(?:if\s+exists\s+)?|detach\s+(?:database\s+)?)(?<names>{$name})",
            'catalog' => "delete\s+from\s+(?:(?<names>{$name})\s*\.\s*)?sqlite_(?:master|schema)\b",
        ];
        $changes = ['destroyed' => [], 'created' => [], 'reshaped' => []];
        foreach ($patterns as $kind => $pattern) {
            preg_match_all("/(?:^|;)\s*{$pattern}/i", $sql, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $names = $match['names'] ?? '';
                switch ($kind) {
                    case 'drop':
                        preg_match_all("/{$name}/", $names, $tables);
                        foreach ($tables[0] as $table) {
                            $changes['destroyed'][] = self::target($table);
                        }
                        break;
                    case 'create':
                        $changes['created'][] = self::target($names)['table'];
                        if (($match['replace'] ?? '') !== '') {
                            $changes['destroyed'][] = self::target($names);
                        }
                        break;
                    case 'rename':
                        $changes['destroyed'][] = self::target($names);
                        $changes['created'][] = self::target($match['to'])['table'];
                        break;
                    case 'renames':
                        preg_match_all("/(?<from>{$name})\s+to\s+(?<to>{$name})/i", $names, $pairs, PREG_SET_ORDER);
                        foreach ($pairs as $pair) {
                            $changes['destroyed'][] = self::target($pair['from']);
                            $changes['created'][] = self::target($pair['to'])['table'];
                        }
                        break;
                    case 'alter':
                    case 'index':
                        $changes['reshaped'][] = self::target($names)['table'];
                        break;
                    default:
                        // DROP DATABASE, DETACH and SQLite catalog deletes take every table in the database.
                        $changes['destroyed'][] = ['database' => $names === '' ? null : self::segments($names)[0], 'table' => null];
                }
            }
        }

        return $changes;
    }

    /** The production name of a table on a connection with this table prefix. */
    public static function logicalName(string $table, string $prefix): string
    {
        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }

    /** @return array<string, array<string, array{string, callable(): array{columns: list<string>, keys: list<array{columns: list<string>, partial: bool}>}}>> */
    private function sqliteCatalog(PDO $pdo): array
    {
        $databases = array_unique(['main', 'temp', ...array_column($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC), 'name')]);
        $catalogs = [];
        foreach ($databases as $database) {
            $catalogs[] = 'SELECT '.$pdo->quote($database).' AS db, type, name, tbl_name, sql FROM '.self::identifier($database).".sqlite_master WHERE type IN ('table', 'index')";
        }
        $definitions = [];
        foreach ($pdo->query(implode(' UNION ALL ', $catalogs).' ORDER BY db, type, name')->fetchAll(PDO::FETCH_ASSOC) as $object) {
            $database = $object['db'];
            unset($object['db']);
            if (! str_starts_with($object['tbl_name'], 'sqlite_')) {
                $definitions[$database][$object['tbl_name']][] = $object;
            }
        }
        $catalog = [];
        foreach ($definitions as $database => $tables) {
            foreach ($tables as $table => $objects) {
                $catalog[$database][$table] = [serialize($objects), fn (): array => $this->sqliteShape($pdo, $database, $table)];
            }
        }

        return $catalog;
    }

    /** @return array{columns: list<string>, keys: list<array{columns: list<string>, partial: bool}>} */
    private function sqliteShape(PDO $pdo, string $database, string $table): array
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

        return ['columns' => $columns, 'keys' => $keys];
    }

    /**
     * MariaDB lists temporary tables in information_schema.TABLES but not in COLUMNS or
     * STATISTICS, so those few are read with SHOW statements. A prefix-length key counts
     * as a key on its column, as it does in the authority.
     *
     * @return array<string, array<string, array{string, callable(): array{columns: list<string>, keys: list<array{columns: list<string>, partial: bool}>}}>>
     */
    private function mariaDbCatalog(PDO $pdo, string $prefix, ?string $schema): array
    {
        $schema ??= $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (! is_string($schema) || $schema === '') {
            return [];
        }
        $filter = 'TABLE_SCHEMA = '.$pdo->quote($schema).' AND TABLE_NAME LIKE '.$pdo->quote(addcslashes($prefix, '\\%_').'%');
        $types = $pdo->query("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE {$filter} AND TABLE_TYPE IN ('BASE TABLE', 'TEMPORARY')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $shapes = [];
        foreach ($types as $table => $type) {
            if ($type === 'TEMPORARY') {
                $qualified = self::identifier($schema, '`').'.'.self::identifier($table, '`');
                $shapes[$table] = [
                    'columns' => array_column($pdo->query('SHOW COLUMNS FROM '.$qualified)->fetchAll(PDO::FETCH_ASSOC), 'Field'),
                    'indexes' => array_map(
                        static fn (array $row): array => ['INDEX_NAME' => $row['Key_name'], 'COLUMN_NAME' => $row['Column_name']],
                        array_filter($pdo->query('SHOW INDEX FROM '.$qualified)->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): bool => ! $row['Non_unique']),
                    ),
                ];
            }
        }
        foreach ($pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE {$filter} ORDER BY TABLE_NAME, ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($types[$row['TABLE_NAME']] ?? null) === 'BASE TABLE') {
                $shapes[$row['TABLE_NAME']]['columns'][] = $row['COLUMN_NAME'];
            }
        }
        foreach ($pdo->query("SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE {$filter} AND NON_UNIQUE = 0 ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($types[$row['TABLE_NAME']] ?? null) === 'BASE TABLE') {
                $shapes[$row['TABLE_NAME']]['indexes'][] = $row;
            }
        }
        $catalog = [];
        foreach ($shapes as $table => $shape) {
            $table = (string) $table;
            if (! str_starts_with($table, $prefix)) {
                continue;
            }
            $keys = [];
            foreach ($shape['indexes'] ?? [] as $index) {
                $keys[$index['INDEX_NAME']][] = $index['COLUMN_NAME'] ?? '<expression>';
            }
            $keys = array_map(static function (array $columns): array {
                sort($columns);

                return ['columns' => $columns, 'partial' => false];
            }, array_values($keys));
            $definition = ['columns' => $shape['columns'] ?? [], 'keys' => $keys];
            $catalog[$schema][$table] = [serialize($definition), static fn (): array => $definition];
        }

        return $catalog;
    }

    /**
     * @param  array{columns: list<string>, keys: list<array{columns: list<string>, partial: bool}>}  $shape
     * @return list<array{table: string, rule: string, subject: string, authority: string}>
     */
    private function validate(string $table, array $shape): array
    {
        ['columns' => $columns, 'keys' => $keys] = $shape;
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

    /** @return list<string> */
    private static function segments(string $name): array
    {
        preg_match_all('/'.self::IDENTIFIER.'/', $name, $parts);

        return array_map(self::unquote(...), $parts[0]);
    }

    /** @return array{database: ?string, table: string} */
    private static function target(string $name): array
    {
        $segments = self::segments($name);

        return ['database' => count($segments) === 2 ? $segments[0] : null, 'table' => $segments[count($segments) - 1]];
    }

    private static function unquote(string $identifier): string
    {
        $identifier = trim($identifier);

        return match ($identifier[0] ?? '') {
            '"' => str_replace('""', '"', substr($identifier, 1, -1)),
            '`' => str_replace('``', '`', substr($identifier, 1, -1)),
            '[' => substr($identifier, 1, -1),
            default => $identifier,
        };
    }

    private static function identifier(string $name, string $quote = '"'): string
    {
        return $quote.str_replace($quote, $quote.$quote, $name).$quote;
    }

    /** @param list<string> $columns */
    private static function keyLabel(array $columns): string
    {
        return '('.implode(', ', $columns).')';
    }
}
