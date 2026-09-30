<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Records the statements run inside each transaction an action opens, and asserts the order
 * that keeps replacing a key's child rows from deadlocking (#910): the parent row's locking
 * read first, each child table read before it is written, no DELETE on a table with no rows.
 * SQLite compiles lockForUpdate() to nothing, so the locking read shows as a plain select.
 */
trait RecordsTransactionStatements
{
    /**
     * One list of statements per transaction the action opened at the level it started at.
     *
     * @return list<list<array{sql: string, bindings: array<int, mixed>}>>
     */
    private function transactionStatements(callable $action): array
    {
        $level = DB::transactionLevel();
        $transactions = [];
        $recording = true;
        Event::listen(TransactionBeginning::class, static function () use (&$transactions, &$recording, $level): void {
            if ($recording && DB::transactionLevel() === $level + 1) {
                $transactions[] = [];
            }
        });
        DB::listen(static function (QueryExecuted $query) use (&$transactions, &$recording, $level): void {
            if ($recording && $transactions !== [] && DB::transactionLevel() > $level) {
                $transactions[array_key_last($transactions)][] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });

        try {
            $action();
        } finally {
            $recording = false;
        }

        return $transactions;
    }

    /**
     * @param  list<array{sql: string, bindings: array<int, mixed>}>  $statements
     * @param  list<string>  $childTables  Tables the transaction writes.
     */
    private function assertParentLockedFirst(array $statements, string $parentTable, int $parentId, array $childTables): void
    {
        $this->assertNotSame([], $statements);
        $this->assertMatchesRegularExpression('/^\s*select\b.*\bfrom "'.$parentTable.'" where "id" = \?/is', $statements[0]['sql'], 'The parent row is read first.');
        $this->assertEquals([$parentId], $statements[0]['bindings']);
        foreach ($childTables as $table) {
            $read = $this->firstStatement($statements, '/^\s*select\b.*\bfrom "'.$table.'" where/is');
            $write = $this->firstStatement($statements, '/^\s*(delete from|insert into) "'.$table.'"/is');
            $this->assertNotNull($read, $table.' is read.');
            $this->assertNotNull($write, $table.' is written.');
            $this->assertLessThan($write, $read, $table.' is read before it is written.');
        }
    }

    /**
     * @param  list<array{sql: string, bindings: array<int, mixed>}>  $statements
     */
    private function deletesOn(array $statements, string $table): int
    {
        return count(array_filter($statements, static fn (array $statement): bool => preg_match('/^\s*delete from "'.$table.'"/i', $statement['sql']) === 1));
    }

    /**
     * @param  list<array{sql: string, bindings: array<int, mixed>}>  $statements
     */
    private function firstStatement(array $statements, string $pattern): ?int
    {
        foreach ($statements as $index => $statement) {
            if (preg_match($pattern, $statement['sql']) === 1) {
                return $index;
            }
        }

        return null;
    }
}
