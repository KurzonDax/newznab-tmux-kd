<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('release_audio_evidence', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('releases_id')->comment('FK to releases.id');
            $table->unsignedInteger('revision');
            $table->char('evidence_hash', 64);
            $table->unsignedSmallInteger('schema_version');
            $table->string('provenance', 32);
            $table->json('release_snapshot');
            $table->boolean('archive_manifest_complete')->nullable();
            $table->boolean('source_file_complete')->nullable();
            $table->boolean('source_starts_at_zero')->nullable();
            $table->boolean('whole_duration_reliable')->nullable();
            $table->boolean('only_one_track_probed')->nullable();
            $table->json('nzb_manifest');
            $table->json('archive_manifest');
            $table->json('sidecar_manifest');
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['releases_id', 'revision']);
            $table->index(['releases_id', 'evidence_hash']);
            $table->index('provenance');

            $table->foreign('releases_id')->references('id')->on('releases')
                ->onDelete('cascade')->onUpdate('cascade');
        });

        Schema::create('release_audio_evidence_tracks', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('release_audio_evidence_id');
            $table->string('source_kind', 16);
            $table->unsignedInteger('source_ordinal');
            $table->string('source_path', 512)->nullable();
            $table->string('raw_filename', 512);
            $table->unsignedInteger('segment_count')->nullable();
            $table->unsignedSmallInteger('disc_number')->nullable();
            $table->unsignedSmallInteger('track_number')->nullable();
            $table->string('normalized_title_hint')->nullable();
            $table->string('normalized_artist_hint')->nullable();
            $table->json('raw_tags')->nullable();
            $table->string('album')->nullable();
            $table->string('album_artist')->nullable();
            $table->string('performer')->nullable();
            $table->string('title')->nullable();
            $table->string('recorded_date', 32)->nullable();
            $table->string('normalized_album')->nullable();
            $table->string('normalized_album_artist')->nullable();
            $table->string('normalized_performer')->nullable();
            $table->string('normalized_title')->nullable();
            $table->string('normalized_date', 32)->nullable();
            $table->string('container', 50)->nullable();
            $table->string('codec', 50)->nullable();
            $table->decimal('whole_duration_seconds', 12, 3)->nullable();
            $table->decimal('decoded_duration_seconds', 12, 3)->nullable();
            $table->boolean('source_file_complete')->nullable();
            $table->boolean('source_starts_at_zero')->nullable();
            $table->boolean('whole_duration_reliable')->nullable();
            $table->string('isrc', 64)->nullable();
            $table->char('musicbrainz_track_id', 36)->nullable();
            $table->char('musicbrainz_recording_id', 36)->nullable();
            $table->char('musicbrainz_release_id', 36)->nullable();
            $table->char('musicbrainz_release_group_id', 36)->nullable();
            $table->char('musicbrainz_artist_id', 36)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('catalog_number', 128)->nullable();
            $table->string('disc_id_like', 255)->nullable();
            $table->timestamps();

            $table->index(['release_audio_evidence_id', 'source_kind', 'source_ordinal'], 'audio_evidence_track_source');
            $table->index('isrc');
            $table->index('musicbrainz_recording_id', 'audio_evidence_track_recording_id');

            $table->foreign('release_audio_evidence_id')->references('id')->on('release_audio_evidence')
                ->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('release_audio_evidence_tracks');
        Schema::dropIfExists('release_audio_evidence');
    }
};
