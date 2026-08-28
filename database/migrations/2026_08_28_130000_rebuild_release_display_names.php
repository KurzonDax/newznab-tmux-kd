<?php

use App\Support\ReleaseDisplayNameFormatter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rebuild releases.display_name for every row: the formatter now unwraps
     * raw-subject decoration (leading separators, counters, wrapping quotes,
     * size annotations) before formatting, and the column is regenerable by
     * design. The formatter is pure and deterministic, so rows the new rules
     * do not affect rewrite to the same value.
     */
    public function up(): void
    {
        DB::table('releases')
            ->select(['id', 'searchname'])
            ->orderBy('id')
            ->chunkById(250, function ($releases): void {
                $caseBindings = [];
                $ids = [];
                $when = [];

                foreach ($releases as $release) {
                    $id = (int) $release->id;
                    $ids[] = $id;
                    $when[] = 'WHEN ? THEN ?';
                    $caseBindings[] = $id;
                    $caseBindings[] = ReleaseDisplayNameFormatter::format((string) $release->searchname);
                }

                if ($ids === []) {
                    return;
                }

                $grammar = DB::connection()->getQueryGrammar();
                $table = $grammar->wrapTable('releases');
                $idColumn = $grammar->wrap('id');
                $displayColumn = $grammar->wrap('display_name');
                $placeholders = implode(', ', array_fill(0, count($ids), '?'));

                DB::update(
                    "UPDATE {$table} SET {$displayColumn} = CASE {$idColumn} ".implode(' ', $when)
                    ." ELSE {$displayColumn} END WHERE {$idColumn} IN ({$placeholders})",
                    [...$caseBindings, ...$ids],
                );
            }, 'id');
    }

    /**
     * Reverse the migrations.
     *
     * Nothing to undo: the rebuild replaces derived values with fresher
     * derived values and makes no schema change.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
};
