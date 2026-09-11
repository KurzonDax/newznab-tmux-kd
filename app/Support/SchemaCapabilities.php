<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use WeakMap;

/** Cache metadata only during an explicit work cycle; never cache ownership or source evidence. */
final class SchemaCapabilities
{
    /** @var WeakMap<PDO, array<string, bool>>|null */
    private static ?WeakMap $cache = null;

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function during(Closure $work): mixed
    {
        if (self::$cache !== null) {
            return $work();
        }
        self::$cache = new WeakMap;
        try {
            return $work();
        } finally {
            self::$cache = null;
        }
    }

    public static function hasTable(string $table): bool
    {
        return self::remember('table:'.$table, static fn (): bool => Schema::hasTable($table));
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return self::remember('column:'.$table.':'.$column, static fn (): bool => Schema::hasColumn($table, $column));
    }

    /** @param list<string> $columns */
    public static function hasColumns(string $table, array $columns): bool
    {
        return self::remember('columns:'.$table.':'.implode(',', $columns), static fn (): bool => Schema::hasColumns($table, $columns));
    }

    /** @param Closure(): bool $read */
    private static function remember(string $key, Closure $read): bool
    {
        if (self::$cache === null) {
            return $read();
        }
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $key = $connection->getDatabaseName().':'.$connection->getTablePrefix().':'.$key;
        $values = self::$cache[$pdo] ?? [];
        $values[$key] ??= $read();
        self::$cache[$pdo] = $values;

        return $values[$key];
    }
}
