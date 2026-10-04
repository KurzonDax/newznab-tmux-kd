<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores each release's audio tag genres as rows on an `audio_genres` lookup, one per genre in
 * the tag value's order, for every category, and indexes the tag year. The names stay out of the
 * shared `genres` table, so the frozen API capabilities genre list never lists them. The next
 * migration fills the rows for existing tag rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_genres', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 100)->comment('A genre name as an audio tag writes it');
            $table->unique('name', 'ux_audio_genres_name');
        });

        Schema::create('release_audio_genres', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('audio_genres_id');
            $table->unsignedTinyInteger('position')->comment('0-based order of the genre in the tag value');
            $table->primary(['audio_genres_id', 'releases_id']);
            $table->index(['releases_id', 'position'], 'ix_release_audio_genres_release');
            $table->foreign('audio_genres_id', 'fk_release_audio_genres_audio_genres_id')->references('id')->on('audio_genres')->cascadeOnDelete();
            $table->foreign('releases_id', 'fk_release_audio_genres_releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });

        Schema::table('release_audio_tags', function (Blueprint $table): void {
            $table->index(['recorded_year', 'releases_id'], 'ix_release_audio_tags_recorded_year');
        });
    }

    public function down(): void
    {
        Schema::table('release_audio_tags', function (Blueprint $table): void {
            $table->dropIndex('ix_release_audio_tags_recorded_year');
        });
        Schema::dropIfExists('release_audio_genres');
        Schema::dropIfExists('audio_genres');
    }
};
