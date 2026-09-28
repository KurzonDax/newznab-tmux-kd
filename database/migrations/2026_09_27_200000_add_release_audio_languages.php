<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores each release's audio languages as rows on a `languages` lookup, for every
 * category, for the Audio filter. The next migration fills them for existing releases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table): void {
            $table->smallIncrements('id');
            $table->string('name', 64)->comment('As LanguageNames names it: English, not en-US');
            $table->unique('name', 'ux_languages_name');
        });

        Schema::create('release_audio_languages', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedSmallInteger('languages_id');
            $table->primary(['languages_id', 'releases_id']);
            $table->index('releases_id', 'ix_release_audio_languages_release');
            $table->foreign('releases_id', 'fk_release_audio_languages_releases_id')->references('id')->on('releases')->cascadeOnDelete();
            $table->foreign('languages_id', 'fk_release_audio_languages_languages_id')->references('id')->on('languages');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_audio_languages');
        Schema::dropIfExists('languages');
    }
};
