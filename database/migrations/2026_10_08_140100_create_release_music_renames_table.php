<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One canonical-rename outcome per accepted album decision (issue #309): applied (with the
     * prior and written value of every release field the rename changed), declined by the rename
     * gate (with the reason), or reverted (with the fields restored) once the decision stopped
     * being the release's current one.
     */
    public function up(): void
    {
        Schema::create('release_music_renames', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('releases_id');
            $table->unsignedBigInteger('release_music_identification_id');
            $table->string('outcome', 16);
            $table->string('reason', 64)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('restored')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->unique('release_music_identification_id', 'release_music_rename_decision');
            $table->index(['releases_id', 'outcome'], 'release_music_rename_release');
            $table->foreign('releases_id', 'FK_rmr_releases')->references('id')->on('releases')
                ->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('release_music_identification_id', 'FK_rmr_rmi')->references('id')->on('release_music_identifications')
                ->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_music_renames');
    }
};
