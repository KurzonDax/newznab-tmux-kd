<?php

declare(strict_types=1);

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores a film's genres and people as rows on the shared `genres` and `people` tables, keyed
 * by `movieinfo_id`, and three TMDB values stored going forward (no backfill). The next
 * migration moves today's text into the rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movieinfo', function (Blueprint $table): void {
            $table->unsignedInteger('vote_count')->nullable()->comment('TMDB vote_count, 0 kept; NULL = not fetched since this column was added');
            $table->string('content_rating_us', 8)->default('')->comment('TMDB US certification from release_dates (MPAA Rating); empty = none');
            $table->string('original_language', 8)->default('')->comment('TMDB original_language (ISO 639-1); empty = unknown');
            $table->index('year', 'ix_movieinfo_year');
        });

        Schema::table('people', function (Blueprint $table): void {
            $table->index('name', 'ix_people_name');
        });

        Schema::create('movie_genres', function (Blueprint $table): void {
            $table->unsignedInteger('movieinfo_id');
            $table->unsignedInteger('genres_id');
            $table->unsignedTinyInteger('position')->comment('0-based order of the genre as the source lists it');
            $table->primary(['genres_id', 'movieinfo_id']);
            $table->index('movieinfo_id', 'ix_movie_genres_movie');
            $table->foreign('movieinfo_id', 'fk_movie_genres_movieinfo_id')->references('id')->on('movieinfo')->cascadeOnDelete();
            $table->foreign('genres_id', 'fk_movie_genres_genres_id')->references('id')->on('genres')->cascadeOnDelete();
        });

        Schema::create('movie_people', function (Blueprint $table): void {
            $table->unsignedInteger('movieinfo_id');
            $table->unsignedInteger('people_id');
            $table->unsignedTinyInteger('role')->comment('0 director, 1 cast');
            $table->unsignedTinyInteger('position')->comment('0-based order within the role');
            $table->primary(['people_id', 'movieinfo_id', 'role']);
            $table->index(['movieinfo_id', 'role', 'position'], 'ix_movie_people_movie');
            $table->foreign('movieinfo_id', 'fk_movie_people_movieinfo_id')->references('id')->on('movieinfo')->cascadeOnDelete();
            $table->foreign('people_id', 'fk_movie_people_people_id')->references('id')->on('people')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_people');
        Schema::dropIfExists('movie_genres');

        Schema::table('movieinfo', function (Blueprint $table): void {
            $table->dropIndex('ix_movieinfo_year');
            $table->dropColumn(['vote_count', 'content_rating_us', 'original_language']);
        });

        DB::table('genres')->where('type', Category::MOVIE_ROOT)->delete();

        // People only films named: no TMDB id and, with the film links gone, no show link.
        // TV adds only people with a TMDB id.
        DB::table('people')->whereNull('tmdb_id')
            ->whereNotExists(static fn ($query) => $query->select(DB::raw(1))->from('video_people')->whereColumn('video_people.people_id', 'people.id'))
            ->delete();

        Schema::table('people', function (Blueprint $table): void {
            $table->dropIndex('ix_people_name');
        });
    }
};
