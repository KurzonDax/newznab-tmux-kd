<?php

use App\Support\ReleaseNameNormalizer;
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
            $table->string('searchname_normalized')->nullable()->after('searchname');
        });

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
                    $caseBindings[] = ReleaseNameNormalizer::normalize((string) $release->searchname);
                }

                if ($ids === []) {
                    return;
                }

                $grammar = DB::connection()->getQueryGrammar();
                $table = $grammar->wrapTable('releases');
                $idColumn = $grammar->wrap('id');
                $normalizedColumn = $grammar->wrap('searchname_normalized');
                $placeholders = implode(', ', array_fill(0, count($ids), '?'));

                DB::update(
                    "UPDATE {$table} SET {$normalizedColumn} = CASE {$idColumn} ".implode(' ', $when)
                    ." ELSE {$normalizedColumn} END WHERE {$idColumn} IN ({$placeholders})",
                    [...$caseBindings, ...$ids],
                );
            }, 'id');

        Schema::table('releases', function (Blueprint $table): void {
            $table->index(
                ['searchname_normalized', 'size'],
                'ix_releases_searchname_normalized_size',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropIndex('ix_releases_searchname_normalized_size');
            $table->dropColumn('searchname_normalized');
        });
    }
};
