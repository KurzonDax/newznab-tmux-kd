<?php

use App\Support\ReleaseDisplayNameFormatter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->string('display_name')->nullable()->after('searchname_normalized');
        });

        // Same chunked CASE backfill as searchname_normalized: the formatter is
        // pure PHP, so the rows are rewritten in bounded batches rather than by
        // a one-off command.
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
     */
    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropColumn('display_name');
        });
    }
};
