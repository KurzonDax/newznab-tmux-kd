<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-game twin of `ix_releases_movieinfo_cat`, so the game-led console list reads are
 * index-only. `ix_releases_consoleinfo_id` stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->index(['consoleinfo_id', 'categories_id', 'passwordstatus', 'postdate', 'adddate', 'completion'], 'ix_releases_consoleinfo_cat');
        });
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropIndex('ix_releases_consoleinfo_cat');
        });
    }
};
