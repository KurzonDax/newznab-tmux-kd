<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One Cover Art Archive front-image lookup per MusicBrainz release or release group: stored
     * (the image's file is named after image_musicbrainz_id), no front image, or failed (retried
     * at next_attempt_at).
     */
    public function up(): void
    {
        Schema::create('music_cover_art_lookups', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->char('musicbrainz_id', 36);
            $table->string('outcome', 16);
            $table->char('image_musicbrainz_id', 36)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'musicbrainz_id'], 'music_cover_art_lookup_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_cover_art_lookups');
    }
};
