<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;

/** Creates SQLite fixture tables from the schema authority; what they keep is in .ai/rules/testing.md. */
final class ProductionTables
{
    private const array TYPES = [
        'INTEGER' => ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'],
        'TEXT' => ['char', 'varchar', 'text', 'mediumtext', 'longtext', 'enum', 'uuid'],
        'REAL' => ['double'],
        'NUMERIC' => ['decimal'],
        'DATETIME' => ['datetime', 'timestamp'],
        'DATE' => ['date'],
        'BLOB' => ['binary', 'blob'],
    ];

    private static ?self $committed = null;

    /** Probes generation expressions; MariaDB functions such as md5() do not exist in SQLite. */
    private static ?PDO $probe = null;

    /** @var array<string, array{columns: list<string>, definitions: array<string, array{type: string, default: ?string, generated: ?string}>, primary: list<string>, uniques: list<list<string>>, autoIncrement: ?string}> */
    private readonly array $tables;

    public function __construct(SchemaAuthority $authority)
    {
        $this->tables = $authority->tables();
    }

    /** The committed schema authority, parsed once per process. */
    public static function fromAuthority(): self
    {
        return self::$committed ??= new self(new SchemaAuthority(dirname(__DIR__, 2).'/database/schema/mariadb-schema.sql'));
    }

    /**
     * Create the production table on a Laravel connection (the default connection when null).
     *
     * @param  ?list<string>  $columns  Production columns to include; null includes every column.
     */
    public function create(string $table, ?array $columns = null, ?string $connection = null): void
    {
        DB::connection($connection)->statement($this->createStatement($table, $columns));
    }

    /**
     * The SQLite CREATE TABLE statement, for callers holding a raw PDO handle.
     *
     * @param  ?list<string>  $columns  Production columns to include; null includes every column.
     */
    public function createStatement(string $table, ?array $columns = null): string
    {
        $definition = $this->tables[$table] ?? throw new RuntimeException("Schema authority has no production table {$table}.");
        if ($columns !== null) {
            if ($columns === []) {
                throw new RuntimeException("Production table {$table} needs at least one column.");
            }
            $unknown = array_values(array_diff($columns, $definition['columns']));
            if ($unknown !== []) {
                throw new RuntimeException("Production table {$table} has no column ".implode(', ', $unknown).'; the schema authority lists '.implode(', ', $definition['columns']).'.');
            }
        }
        // Production column order, whatever order the caller named them in.
        $included = $columns === null ? $definition['columns'] : array_values(array_intersect($definition['columns'], $columns));
        $identity = in_array($definition['autoIncrement'], $included, true) ? $definition['autoIncrement'] : null;

        $parts = [];
        foreach ($included as $column) {
            $parts[] = $this->column($table, $column, $definition['definitions'][$column], $included, $column === $identity);
        }
        $keys = $definition['primary'] === [] ? $definition['uniques'] : [$definition['primary'], ...$definition['uniques']];
        foreach (array_unique($keys, SORT_REGULAR) as $index => $key) {
            if ($key === [$identity] || array_diff($key, $included) !== []) {
                continue;
            }
            $constraint = $index === 0 && $definition['primary'] !== [] && $identity === null ? 'PRIMARY KEY' : 'UNIQUE';
            $parts[] = $constraint.' ('.implode(', ', array_map(self::identifier(...), $key)).')';
        }

        return 'CREATE TABLE '.self::identifier($table)." (\n  ".implode(",\n  ", $parts)."\n)";
    }

    /**
     * @param  array{type: string, default: ?string, generated: ?string}  $definition
     * @param  list<string>  $included
     */
    private function column(string $table, string $column, array $definition, array $included, bool $identity): string
    {
        foreach (self::TYPES as $sqliteType => $mariaDbTypes) {
            if (in_array($definition['type'], $mariaDbTypes, true)) {
                $sql = self::identifier($column).' '.$sqliteType;
                break;
            }
        }
        if (! isset($sql)) {
            throw new RuntimeException("Production column {$table}.{$column} has unsupported type {$definition['type']}; map it in ".self::class.'::TYPES.');
        }
        if ($identity) {
            return $sql.' PRIMARY KEY AUTOINCREMENT';
        }
        if ($definition['generated'] !== null) {
            preg_match_all('/`([^`]+)`/', $definition['generated'], $references);
            $missing = array_values(array_unique(array_diff($references[1], $included)));
            sort($missing);
            if ($missing !== []) {
                throw new RuntimeException("Production column {$table}.{$column} is generated from ".implode(', ', $missing).'; include those columns too.');
            }
            $source = array_map(static fn (string $reference): string => 'NULL AS '.self::identifier($reference), array_unique($references[1])) ?: ['NULL'];
            try {
                (self::$probe ??= new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))
                    ->prepare('SELECT '.$definition['generated'].' FROM (SELECT '.implode(', ', $source).')');
            } catch (PDOException $exception) {
                throw new RuntimeException("Production column {$table}.{$column} is generated by an expression SQLite cannot evaluate ({$exception->getMessage()}); omit that column.", previous: $exception);
            }

            return $sql.' GENERATED ALWAYS AS ('.$definition['generated'].') VIRTUAL';
        }

        return $definition['default'] === null ? $sql : $sql.' DEFAULT '.$this->default($table, $column, $definition['default']);
    }

    private function default(string $table, string $column, string $literal): string
    {
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $literal) || strcasecmp($literal, 'NULL') === 0) {
            return $literal;
        }
        if (strcasecmp($literal, 'current_timestamp()') === 0) {
            return 'CURRENT_TIMESTAMP';
        }
        if (preg_match("/^'(.*)'$/s", $literal, $quoted)) {
            $value = preg_replace_callback("/''|\\\\(.)/s", static fn (array $escape): string => match ($escape[0]) {
                "''" => "'",
                '\\n' => "\n",
                '\\r' => "\r",
                '\\t' => "\t",
                '\\0' => "\0",
                default => $escape[1],
            }, $quoted[1]);

            return "'".str_replace("'", "''", $value)."'";
        }

        throw new RuntimeException("Production column {$table}.{$column} has unsupported default {$literal}.");
    }

    private static function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
