<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_info_probes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('releases_id');
            $table->dateTime('captured_at');
            $table->string('source_kind', 32);
            $table->text('source_filename')->nullable();
            $table->string('source_completeness', 16);
            $table->unsignedSmallInteger('schema_version');
            $table->text('embedded_title')->nullable();
            $table->text('container_format')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('overall_bitrate_bps')->nullable();
            $table->json('music_tags')->nullable();
            $table->json('diagnostic_raw')->nullable();
            $table->boolean('diagnostic_filtered')->default(false);
            $table->boolean('diagnostic_truncated')->default(false);
            $table->timestamps();
            $table->index(['releases_id', 'captured_at']);
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });

        Schema::create('media_info_tracks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_info_probe_id')->constrained('media_info_probes')->cascadeOnDelete();
            $table->string('type', 16);
            $table->unsignedSmallInteger('track_index');
            $table->text('source_id')->nullable();
            $table->text('stream_order')->nullable();
            $table->text('title')->nullable();
            $table->text('language')->nullable();
            $table->text('format')->nullable();
            $table->text('codec')->nullable();
            $table->boolean('is_default')->nullable();
            $table->boolean('is_forced')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('bitrate_bps')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->text('aspect_ratio')->nullable();
            $table->decimal('frame_rate', 10, 3)->nullable();
            $table->text('profile')->nullable();
            $table->unsignedSmallInteger('bit_depth')->nullable();
            $table->text('hdr_format')->nullable();
            $table->text('color_primaries')->nullable();
            $table->text('transfer_characteristics')->nullable();
            $table->text('matrix_coefficients')->nullable();
            $table->unsignedSmallInteger('channels')->nullable();
            $table->text('channel_layout')->nullable();
            $table->unsignedInteger('sample_rate_hz')->nullable();
            $table->json('diagnostic_raw')->nullable();
            $table->boolean('diagnostic_filtered')->default(false);
            $table->boolean('diagnostic_truncated')->default(false);
            $table->timestamps();
            $table->unique(['media_info_probe_id', 'type', 'track_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_info_tracks');
        Schema::dropIfExists('media_info_probes');
    }
};
