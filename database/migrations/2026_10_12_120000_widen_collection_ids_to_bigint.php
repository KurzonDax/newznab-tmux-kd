<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * collections.id and the three 32-bit columns that store it become BIGINT UNSIGNED (issue #1064).
 *
 * MariaDB refuses to retype the parent while a foreign key references it and a child while
 * its foreign key exists, so each child loses its key in the statement that retypes it and
 * both keys are added back last. Every step reads information_schema first and does only
 * what is missing, so an interrupted run completes when run again. Header ingestion and
 * release formation must not run meanwhile: between the first and the last step nothing
 * cascades a collection's deletion.
 */
return new class extends Migration
{
    /**
     * The columns in the order they are retyped: the column, what follows its type, and for a
     * child the key that references collections (id).
     *
     * @var array<string, array{column: string, attributes: string, key: ?string}>
     */
    private const array COLUMNS = [
        'binaries' => ['column' => 'collections_id', 'attributes' => 'NOT NULL DEFAULT 0', 'key' => 'FK_Collections'],
        'collection_groups' => ['column' => 'collections_id', 'attributes' => 'NOT NULL', 'key' => 'fk_collection_groups_collection'],
        'collections' => ['column' => 'id', 'attributes' => 'NOT NULL AUTO_INCREMENT', 'key' => null],
        'obfuscation_recovery_publications' => ['column' => 'collections_id', 'attributes' => 'NULL DEFAULT NULL', 'key' => null],
    ];

    private const int INT_UNSIGNED_MAX = 4294967295;

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $this->retype('BIGINT');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $this->refuseWhatIntUnsignedCannotHold();
        $this->retype('INT');
    }

    /**
     * Narrowing keeps the AUTO_INCREMENT counter: with the counter above the ceiling every
     * later insert of a collection fails, although no stored id is out of range.
     */
    private function refuseWhatIntUnsignedCannotHold(): void
    {
        foreach (self::COLUMNS as $table => ['column' => $column]) {
            if (DB::select("SELECT 1 FROM `{$table}` WHERE `{$column}` > ? LIMIT 1", [self::INT_UNSIGNED_MAX]) !== []) {
                throw new RuntimeException("{$table}.{$column} holds a value above ".self::INT_UNSIGNED_MAX.', which INT UNSIGNED cannot store. Nothing was changed.');
            }
        }

        $counter = DB::selectOne(
            'SELECT AUTO_INCREMENT AS counter
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND AUTO_INCREMENT > ?',
            ['collections', self::INT_UNSIGNED_MAX]
        );
        if ($counter !== null) {
            throw new RuntimeException(
                "The AUTO_INCREMENT counter of collections is {$counter->counter}, above ".self::INT_UNSIGNED_MAX.'. Narrowing '
                .'collections.id keeps the counter, so every later insert of a collection would fail. Nothing was changed.'
            );
        }
    }

    /**
     * Retypes each column still of the other type, then adds back each absent key. A key is
     * dropped only in the statement that retypes its column: a child already retyped keeps it.
     *
     * @param  'BIGINT'|'INT'  $type
     */
    private function retype(string $type): void
    {
        $other = $type === 'BIGINT' ? 'int' : 'bigint';
        foreach (self::COLUMNS as $table => ['column' => $column, 'attributes' => $attributes, 'key' => $key]) {
            if ($this->dataType($table, $column) !== $other) {
                continue;
            }
            $dropKey = $key !== null && $this->hasForeignKey($table, $key) ? "DROP FOREIGN KEY `{$key}`, " : '';
            DB::statement("ALTER TABLE `{$table}` {$dropKey}MODIFY `{$column}` {$type} UNSIGNED {$attributes}");
        }

        $missing = array_filter(
            self::COLUMNS,
            fn (array $child, string $table): bool => $child['key'] !== null && ! $this->hasForeignKey($table, $child['key']),
            ARRAY_FILTER_USE_BOTH,
        );
        if ($missing === []) {
            return;
        }

        // Foreign key checks are on while a key is added, whatever the session had, so the
        // existing rows are validated: a child row whose collection is missing stops it.
        $checks = (int) DB::selectOne('SELECT @@SESSION.foreign_key_checks AS checks')->checks;
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        try {
            foreach ($missing as $table => ['column' => $column, 'key' => $key]) {
                $this->addKey($table, $column, $key, $type);
            }
        } finally {
            DB::statement("SET FOREIGN_KEY_CHECKS={$checks}");
        }
    }

    private function addKey(string $table, string $column, string $key, string $type): void
    {
        try {
            DB::statement(
                "ALTER TABLE `{$table}` ADD CONSTRAINT `{$key}` FOREIGN KEY (`{$column}`)
                    REFERENCES `collections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE"
            );
        } catch (QueryException $exception) {
            throw new RuntimeException(
                "Foreign key {$key} could not be added to {$table}.{$column}. The four collection id columns are "
                ."already {$type} UNSIGNED; only adding the keys back is left, and running the migration again retries it. "
                .$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private function dataType(string $table, string $column): ?string
    {
        $type = DB::selectOne(
            'SELECT DATA_TYPE AS data_type
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        return $type === null ? null : strtolower((string) $type->data_type);
    }

    private function hasForeignKey(string $table, string $key): bool
    {
        return DB::select(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_TYPE = 'FOREIGN KEY'
               AND TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?",
            [$table, $key]
        ) !== [];
    }
};
