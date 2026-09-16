<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MovieInfo;
use App\Services\MetadataProcessing\MovieReleaseBackfill;
use Illuminate\Console\Command;

class BackfillMovieReleaseLinks extends Command
{
    protected $signature = 'movies:backfill-links';

    protected $description = 'Link releases to existing movie records without fetching metadata';

    public function handle(MovieReleaseBackfill $backfill): int
    {
        $linked = 0;
        MovieInfo::query()->whereExists(fn ($releases) => $releases->selectRaw('1')->from('releases')
            ->whereColumn('releases.imdbid', 'movieinfo.imdbid')->whereNull('releases.movieinfo_id'))
            ->select(['id', 'imdbid'])->chunkById(500, function ($movies) use ($backfill, &$linked): void {
                foreach ($movies as $movie) {
                    $linked += $backfill->forMovie($movie);
                }
            });
        $this->info("Linked {$linked} releases.");

        return self::SUCCESS;
    }
}
