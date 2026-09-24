<?php

declare(strict_types=1);

use App\Services\Releases\ReleaseDerivedFacts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the season and episodes each TV release declares, and fills it for every
 * existing TV release with a show.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_tv_episodes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('releases_id');
            $table->unsignedSmallInteger('season')->comment('0 = Specials');
            $table->unsignedSmallInteger('episode')->nullable()->comment('As declared, 0 included; NULL = the whole season');
            $table->index('releases_id', 'ix_release_tv_episodes_releases_id');
            $table->foreign('releases_id', 'fk_release_tv_episodes_releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });

        app(ReleaseDerivedFacts::class)->fillTvEpisodes();
    }

    public function down(): void
    {
        Schema::dropIfExists('release_tv_episodes');
    }
};
