<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a parent row's rows in its child tables. Under REPEATABLE-READ a DELETE that
 * matches nothing still locks the index gap where the key would sit, and every key with no
 * rows yet shares the gap after the last stored key, so two workers writing two new keys
 * deadlock on each other's inserts. This locks the parent row, reads each child table and
 * deletes only from a table that holds the key's rows.
 */
final class ChildRows
{
    /** Two neighbouring stored keys whose new rows land between them still deadlock. */
    private const int ATTEMPTS = 3;

    /**
     * @param  array<string, list<array<string, mixed>>>  $rows  Each child table's new rows, without the key column.
     * @param  (Closure(): void)|null  $alsoWrite  Other writes for the same transaction, run after the child tables are read.
     */
    public static function replace(string $parentTable, int $parentId, string $keyColumn, array $rows, ?Closure $alsoWrite = null): void
    {
        $keyed = [];
        foreach ($rows as $table => $tableRows) {
            $keyed[$table] = array_map(static fn (array $row): array => [$keyColumn => $parentId] + $row, $tableRows);
        }

        // An enclosing transaction may already have an obsolete consistent-read snapshot, which
        // the reads below would use; a DELETE always sees the stored rows.
        if (DB::transactionLevel() > 0) {
            DB::transaction(static fn () => self::write($keyed, $keyColumn, $parentId, null, $alsoWrite));

            return;
        }

        DB::transaction(static function () use ($parentTable, $parentId, $keyed, $keyColumn, $alsoWrite): void {
            // First: the snapshot starts at the first plain read, so a read before this lock
            // would hide rows a writer this one waited for has committed.
            DB::table($parentTable)->where('id', $parentId)->lockForUpdate()->value('id');
            $stored = [];
            foreach (array_keys($keyed) as $table) {
                $stored[$table] = DB::table($table)->where($keyColumn, $parentId)->exists();
            }
            self::write($keyed, $keyColumn, $parentId, $stored, $alsoWrite);
        }, self::ATTEMPTS);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $keyed
     * @param  array<string, bool>|null  $stored  Whether each table holds the key's rows; null deletes from every table.
     * @param  (Closure(): void)|null  $alsoWrite
     */
    private static function write(array $keyed, string $keyColumn, int $parentId, ?array $stored, ?Closure $alsoWrite): void
    {
        if ($alsoWrite !== null) {
            $alsoWrite();
        }
        foreach ($keyed as $table => $tableRows) {
            if ($stored[$table] ?? true) {
                DB::table($table)->where($keyColumn, $parentId)->delete();
            }
            DB::table($table)->insert($tableRows);
        }
    }
}
