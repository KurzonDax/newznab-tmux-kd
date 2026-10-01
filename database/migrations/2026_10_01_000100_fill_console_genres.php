<?php

declare(strict_types=1);

use App\Models\Category;
use App\Services\MetadataProcessing\ConsoleGenres;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Splits every console game's combined genre title (`Shooter,Adventure`) into one
 * `console_genres` row per name, each the lowest-id Console genre with that title, and points
 * `genres_id` at the first. Games are read in primary-key chunks and a game whose rows already
 * match is left alone, so a failed run can be re-run. Console genres nothing links to any more
 * (the combined titles and duplicate rows) are then deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $genres = app(ConsoleGenres::class);
        DB::table('consoleinfo')->select(['id', 'genres_id'])->whereNotNull('genres_id')
            ->chunkById(500, static function (Collection $games) use ($genres): void {
                $titles = DB::table('genres')->whereIn('id', $games->pluck('genres_id')->unique()->all())->pluck('title', 'id');
                foreach ($games as $game) {
                    $title = $titles[$game->genres_id] ?? null;
                    if ($title === null) {
                        continue;
                    }
                    $id = (int) $game->id;
                    // Filled already (by an earlier run or a lookup): its first row is its genre.
                    if (($genres->stored($id)[0] ?? null) === (int) $game->genres_id) {
                        continue;
                    }
                    $genres->replace($id, $genres->ids(ConsoleGenres::split((string) $title)));
                }
            });

        DB::table('genres')->where('type', Category::GAME_ROOT)
            ->whereNotExists(static fn ($query) => $query->select(DB::raw(1))->from('consoleinfo')->whereColumn('consoleinfo.genres_id', 'genres.id'))
            ->whereNotExists(static fn ($query) => $query->select(DB::raw(1))->from('console_genres')->whereColumn('console_genres.genres_id', 'genres.id'))
            ->delete();
    }

    public function down(): void
    {
        // The rows stay: the storage migration's down() drops them. The combined titles are not
        // rebuilt, so a game keeps only its first genre in `genres_id`.
    }
};
