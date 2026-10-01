<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores a console game's genres as rows on the shared `genres` table, one per genre and
 * keyed by `consoleinfo_id`, as Movies and TV store theirs. `consoleinfo.genres_id` stays
 * and holds the first genre. The next migration moves today's combined titles into the rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('console_genres', function (Blueprint $table): void {
            $table->unsignedInteger('consoleinfo_id');
            $table->unsignedInteger('genres_id');
            $table->unsignedTinyInteger('position')->comment('0-based order of the genre as the source lists it');
            $table->primary(['genres_id', 'consoleinfo_id']);
            $table->index('consoleinfo_id', 'ix_console_genres_console');
            $table->foreign('consoleinfo_id', 'fk_console_genres_consoleinfo_id')->references('id')->on('consoleinfo')->cascadeOnDelete();
            $table->foreign('genres_id', 'fk_console_genres_genres_id')->references('id')->on('genres')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('console_genres');
    }
};
