<?php

declare(strict_types=1);

use App\Services\Releases\ReleaseDerivedFacts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores each release's resolution and source for every category and fills them for
 * every existing release. Alter `releases` with docs/releases-table-optimization.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->unsignedTinyInteger('resolution')->default(0)
                ->comment('App\Enums\ReleaseResolution: measured video size, else the name; 0 unknown');
            $table->unsignedTinyInteger('source')->default(0)
                ->comment('App\Enums\ReleaseSource: from the name; 0 unknown');
        });

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('releases', function (Blueprint $table): void {
            $table->integer('category_band')->virtualAs('FLOOR(categories_id / 1000) * 1000')
                ->comment('Thousand-band of categories_id: 5000 for every TV category');
        });

        app(ReleaseDerivedFacts::class)->fillAll();

        Schema::table('releases', function (Blueprint $table): void {
            $table->index(['category_band', 'postdate', 'id', 'resolution', 'source', 'categories_id', 'passwordstatus'], 'ix_releases_band_posted');
            $table->index(['category_band', 'adddate', 'id', 'resolution', 'source', 'categories_id', 'passwordstatus'], 'ix_releases_band_added');
            $table->index(['category_band', 'resolution', 'source', 'categories_id', 'passwordstatus'], 'ix_releases_band_count');
            $table->index(['videos_id', 'postdate'], 'ix_releases_videos_posted');
            $table->index(['videos_id', 'adddate'], 'ix_releases_videos_added');
        });
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('releases', function (Blueprint $table): void {
                $table->dropIndex('ix_releases_band_posted');
                $table->dropIndex('ix_releases_band_added');
                $table->dropIndex('ix_releases_band_count');
                $table->dropIndex('ix_releases_videos_posted');
                $table->dropIndex('ix_releases_videos_added');
                $table->dropColumn('category_band');
            });
        }

        Schema::table('releases', function (Blueprint $table): void {
            $table->dropColumn(['resolution', 'source']);
        });
    }
};
