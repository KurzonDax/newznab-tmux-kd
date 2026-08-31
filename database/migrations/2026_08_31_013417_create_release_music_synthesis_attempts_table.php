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
        Schema::create('release_music_synthesis_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('releases_id');
            $table->string('algorithm_version', 64);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_operational_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['releases_id', 'algorithm_version'],
                'release_music_synthesis_version',
            );
            $table->index(
                ['next_attempt_at', 'lease_expires_at'],
                'release_music_synthesis_retry',
            );
            $table->foreign('releases_id')->references('id')->on('releases')
                ->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('release_music_synthesis_attempts');
    }
};
