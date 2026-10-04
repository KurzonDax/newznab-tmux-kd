<?php

declare(strict_types=1);

use App\Services\AudioProcessing\AudioGenres;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Splits every audio tag row's genre value (`Rock; Pop`, `Americana / Country`) into one
 * `release_audio_genres` row per name, each its `audio_genres` row. Tag rows are read in
 * primary-key chunks and a release whose rows already match is left alone, so a failed run can
 * be re-run and a second run changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $genres = app(AudioGenres::class);
        DB::table('release_audio_tags')->select(['id', 'releases_id', 'genre'])->whereNotNull('genre')
            ->chunkById(500, static function (Collection $tags) use ($genres): void {
                foreach ($tags as $tag) {
                    $releasesId = (int) $tag->releases_id;
                    $ids = $genres->ids(AudioGenres::split((string) $tag->genre));
                    if ($ids === $genres->stored($releasesId)) {
                        continue;
                    }
                    $genres->replace($releasesId, $ids);
                }
            });
    }

    public function down(): void
    {
        // The rows stay: the storage migration's down() drops them.
    }
};
