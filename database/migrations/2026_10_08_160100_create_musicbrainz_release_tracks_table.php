<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An accepted MusicBrainz album's track list (issue #313, section B), stored once per
     * MusicBrainz release in MusicBrainz's medium and track order. A decision names the release
     * it accepted in musicbrainz_release_id (indexed here, so the other releases naming it can be
     * found); the per-decision track text #308 stored is dropped.
     */
    public function up(): void
    {
        Schema::create('musicbrainz_release_tracks', function (Blueprint $table): void {
            $table->char('musicbrainz_release_id', 36);
            $table->unsignedSmallInteger('medium_position');
            $table->unsignedSmallInteger('track_position');
            $table->text('title');
            $table->unsignedInteger('length_ms')->nullable();
            $table->text('artist_credit')->nullable()->comment('The credit as printed on the track, join phrases included');
            $table->primary(['musicbrainz_release_id', 'medium_position', 'track_position']);
        });

        Schema::table('release_music_identifications', function (Blueprint $table): void {
            $table->index('musicbrainz_release_id', 'release_music_identity_release');
            $table->dropColumn(['accepted_track_titles', 'accepted_track_artist_credits']);
        });
    }

    public function down(): void
    {
        Schema::table('release_music_identifications', function (Blueprint $table): void {
            $table->text('accepted_track_titles')->nullable()->after('accepted_artist_credit');
            $table->text('accepted_track_artist_credits')->nullable()->after('accepted_track_titles');
            $table->dropIndex('release_music_identity_release');
        });

        Schema::dropIfExists('musicbrainz_release_tracks');
    }
};
