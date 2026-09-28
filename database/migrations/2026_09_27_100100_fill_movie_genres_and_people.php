<?php

declare(strict_types=1);

use App\Services\MetadataProcessing\MovieCredits;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moves every film's `genre`, `director` and `actors` text into `movie_genres` and
 * `movie_people` through the same method a fetch uses. Films are read in primary-key chunks
 * and a film whose rows already match is left alone, so a failed run can be re-run. Each
 * film's next refresh replaces its rows from TMDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        $credits = app(MovieCredits::class);
        DB::table('movieinfo')->select(['id', 'genre', 'director', 'actors'])
            ->chunkById(500, static function (Collection $films) use ($credits): void {
                foreach ($films as $film) {
                    $credits->syncFromText((int) $film->id, (string) $film->genre, (string) $film->director, (string) $film->actors);
                }
            });
    }

    public function down(): void
    {
        // The rows stay: they match the film text, and the storage migration's down() drops them.
    }
};
