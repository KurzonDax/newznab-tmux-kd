<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An accepted MusicBrainz album's credited artists (issue #313, section D): each artist's
     * canonical name and its "Artist name" and "Search hint" aliases, stored once per artist,
     * and each accepted album decision's link to its album artists in credit order.
     */
    public function up(): void
    {
        Schema::create('musicbrainz_artists', function (Blueprint $table): void {
            $table->char('musicbrainz_artist_id', 36)->primary();
            $table->text('name')->comment("The artist's MusicBrainz canonical name");
        });

        Schema::create('musicbrainz_artist_aliases', function (Blueprint $table): void {
            $table->char('musicbrainz_artist_id', 36);
            $table->unsignedSmallInteger('position')->comment('0-based, in MusicBrainz response order');
            $table->text('name');
            $table->string('type', 16)->comment('artist_name or search_hint');
            $table->primary(['musicbrainz_artist_id', 'position']);
            $table->foreign('musicbrainz_artist_id', 'fk_mb_artist_aliases_artist')->references('musicbrainz_artist_id')->on('musicbrainz_artists')
                ->cascadeOnDelete();
        });

        Schema::create('release_music_identification_artists', function (Blueprint $table): void {
            $table->unsignedBigInteger('release_music_identifications_id');
            $table->unsignedSmallInteger('position')->comment('0-based, in artist credit order');
            $table->char('musicbrainz_artist_id', 36);
            $table->primary(['release_music_identifications_id', 'position']);
            $table->index('musicbrainz_artist_id', 'ix_rmi_artists_artist');
            $table->foreign('release_music_identifications_id', 'FK_rmiart_rmi')->references('id')->on('release_music_identifications')
                ->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('musicbrainz_artist_id', 'FK_rmiart_mb_artist')->references('musicbrainz_artist_id')->on('musicbrainz_artists');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_music_identification_artists');
        Schema::dropIfExists('musicbrainz_artist_aliases');
        Schema::dropIfExists('musicbrainz_artists');
    }
};
