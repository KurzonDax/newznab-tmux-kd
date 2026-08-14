<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carry the collection's deterministic identity (collections.collectionhash,
     * sha1(cleanedSubject . totalFiles) as raw BINARY(20)) onto the release it
     * becomes, and enforce uniqueness at the database level so a re-formed
     * collection (group reset, backfill re-walk, provider change) cannot insert
     * a duplicate release even after the original CBP rows were cleaned up.
     *
     * Nullable is load-bearing: unique indexes permit unlimited NULLs on both
     * MySQL/MariaDB and SQLite, so pre-existing releases and NZB-imported
     * releases (which have no collection) are unaffected and no backfill is
     * needed. On large installs the index build may take a while, but no row
     * rewrite happens since the column is added NULL.
     */
    public function up(): void
    {
        if (! Schema::hasTable('releases') || Schema::hasColumn('releases', 'collectionhash')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('releases', function (Blueprint $table): void {
                $table->binary('collectionhash')->nullable();
                $table->unique('collectionhash', 'ux_releases_collectionhash');
            });

            return;
        }

        DB::statement(
            'ALTER TABLE releases ADD COLUMN collectionhash BINARY(20) NULL, '
            .'ADD UNIQUE INDEX ux_releases_collectionhash (collectionhash)'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('releases') || ! Schema::hasColumn('releases', 'collectionhash')) {
            return;
        }

        Schema::table('releases', function (Blueprint $table): void {
            $table->dropUnique('ux_releases_collectionhash');
            $table->dropColumn('collectionhash');
        });
    }
};
