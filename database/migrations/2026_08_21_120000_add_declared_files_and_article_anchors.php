<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The declared file count, preserved past stale promotion, plus the article numbers it spanned.
 *
 * `collections.totalfiles` is overwritten with the number of files actually seen when a stale
 * collection is promoted -- that overwrite is what lets an incomplete collection become a release
 * at all. The side effect is that for exactly the releases worth repairing, the header-declared
 * total is gone by the time the release exists, so nothing can tell that a file is missing
 * entirely. `declaredfiles` is written once at collection insert and never rewritten.
 *
 * `firstarticle` / `lastarticle` are the min and max `parts.number` the collection held, captured
 * at release creation because NZB creation deletes the CBP rows. They give the header re-scan an
 * exact article window instead of a date bisection. Nullable: legacy rows have no way back to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table): void {
            $table->unsignedInteger('declaredfiles')
                ->default(0)
                ->after('totalfiles')
                ->comment('Files the [n/N] header token declared; written once, never overwritten');

            $table->unsignedBigInteger('firstarticle')
                ->nullable()
                ->after('declaredfiles')
                ->comment('Lowest parts.number seen for this collection');

            $table->unsignedBigInteger('lastarticle')
                ->nullable()
                ->after('firstarticle')
                ->comment('Highest parts.number seen for this collection');
        });

        // Seed the collections already in flight from `totalfiles`. For one that has not gone
        // stale yet that *is* the header-declared total; for one that has, it is the overwritten
        // count, which is no worse than the 0 the column would otherwise hold. Left at 0, a
        // delaytime window's worth of collections would reach ReleaseCreationService with no
        // declared count -- and since that is what feeds the completion measurer, the obfuscated
        // single-segment branch (which needs a file count) would silently go missing and measure
        // those releases at a fraction of a percent.
        DB::table('collections')->update(['declaredfiles' => DB::raw('totalfiles')]);

        Schema::table('releases', function (Blueprint $table): void {
            $table->unsignedInteger('declaredfiles')
                ->nullable()
                ->after('totalpart')
                ->comment('Files the headers declared; null = unresolved (legacy), 0 = no usable declaration');

            $table->unsignedBigInteger('firstarticle')
                ->nullable()
                ->after('declaredfiles')
                ->comment('Lowest article number the collection held');

            $table->unsignedBigInteger('lastarticle')
                ->nullable()
                ->after('firstarticle')
                ->comment('Highest article number the collection held');
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table): void {
            $table->dropColumn(['declaredfiles', 'firstarticle', 'lastarticle']);
        });

        Schema::table('releases', function (Blueprint $table): void {
            $table->dropColumn(['declaredfiles', 'firstarticle', 'lastarticle']);
        });
    }
};
