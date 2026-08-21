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
        Schema::create('release_audio_tags', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('releases_id')->comment('FK to releases.id');
            $table->string('album', 255)->nullable();
            $table->string('album_performer', 255)->nullable();
            $table->string('performer', 255)->nullable();
            $table->string('track_name', 255)->nullable();
            $table->unsignedSmallInteger('track_position')->nullable();
            $table->unsignedSmallInteger('track_position_total')->nullable();
            $table->string('genre', 100)->nullable();
            $table->string('recorded_date', 32)->nullable()
                ->comment('Raw MediaInfo value: "2019", "2019-04-01", "2019-04-01 00:00:00 UTC"');
            $table->unsignedSmallInteger('recorded_year')->nullable();
            $table->char('musicbrainz_album_id', 36)->nullable();
            $table->char('musicbrainz_artist_id', 36)->nullable();
            $table->char('musicbrainz_track_id', 36)->nullable();
            $table->char('musicbrainz_release_group_id', 36)->nullable();
            $table->string('source_file', 255)->nullable();
            $table->string('audio_format', 50)->nullable();
            $table->json('raw_tags')->nullable();
            $table->unsignedTinyInteger('has_preview')->default(0);
            $table->string('preview_extension', 8)->nullable();
            $table->string('preview_mime', 32)->nullable();
            $table->unsignedSmallInteger('preview_seconds')->nullable();
            $table->unsignedInteger('preview_bytes')->nullable();
            $table->unsignedTinyInteger('has_spectrogram')->default(0);
            $table->timestamps();

            $table->unique('releases_id');
            $table->index('album');
            $table->index('musicbrainz_album_id');

            $table->foreign('releases_id')->references('id')->on('releases')
                ->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('release_audio_tags');
    }
};
