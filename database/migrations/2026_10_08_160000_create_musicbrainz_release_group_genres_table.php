<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An accepted MusicBrainz album's genres (issue #313, section A), stored once per release
     * group on the `audio_genres` lookup in vote order, and indexed decisions by release group so
     * a group's other releases can be found. A release's `release_audio_genres` rows now come from
     * these rows when its current decision accepts the album, else from its audio tag.
     */
    public function up(): void
    {
        Schema::create('musicbrainz_release_group_genres', function (Blueprint $table): void {
            $table->char('musicbrainz_release_group_id', 36);
            $table->unsignedInteger('audio_genres_id');
            $table->unsignedTinyInteger('position')->comment('0-based: vote count highest first, then name A to Z');
            $table->primary(['musicbrainz_release_group_id', 'position']);
            $table->unique(['musicbrainz_release_group_id', 'audio_genres_id'], 'ux_mb_release_group_genres_genre');
            $table->foreign('audio_genres_id', 'fk_mb_release_group_genres_audio_genres_id')->references('id')->on('audio_genres')->cascadeOnDelete();
        });

        Schema::table('release_music_identifications', function (Blueprint $table): void {
            $table->index('musicbrainz_release_group_id', 'release_music_identity_release_group');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('audio_genres', function (Blueprint $table): void {
                $table->string('name', 100)->comment('A genre name as an audio tag or MusicBrainz writes it')->change();
            });
            Schema::table('release_audio_genres', function (Blueprint $table): void {
                $table->unsignedTinyInteger('position')->comment("0-based order of the genre in the release's genre list")->change();
            });
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('release_audio_genres', function (Blueprint $table): void {
                $table->unsignedTinyInteger('position')->comment('0-based order of the genre in the tag value')->change();
            });
            Schema::table('audio_genres', function (Blueprint $table): void {
                $table->string('name', 100)->comment('A genre name as an audio tag writes it')->change();
            });
        }

        Schema::table('release_music_identifications', function (Blueprint $table): void {
            $table->dropIndex('release_music_identity_release_group');
        });

        Schema::dropIfExists('musicbrainz_release_group_genres');
    }
};
