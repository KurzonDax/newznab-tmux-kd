<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Release;
use App\Models\ReleaseFile;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseDeletionProtection;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Console\Command;

class NntmuxRemoveBadReleases extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:remove-bad';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update releases that have passworded files inside archives and remove releases that cannot be PPA\'d properly';

    /**
     * Execute the console command.
     *
     *
     * @throws \Exception
     */
    public function handle(): void
    {
        // Select releases with password status -2 and smaller and delete them. Also delete the files from the filesystem.
        $badReleases = ReleaseDeletionProtection::apply(Release::query())
            ->where('passwordstatus', '<=', -2)
            ->get();
        $releaseManagement = app(ReleaseManagementService::class);
        $nzb = app(NzbService::class);
        $releaseImage = new ReleaseImageService;

        foreach ($badReleases as $badRelease) {
            $releaseManagement->deleteSingleIfUnclaimed(
                ['g' => (string) $badRelease->guid, 'i' => (int) $badRelease->id],
                $nzb,
                $releaseImage,
            );
        }

        $passReleases = ReleaseFile::query()->where('passworded', '=', 1)->groupBy('releases_id')->get();

        $count = 0;
        foreach ($passReleases as $passRelease) {
            $releasesId = (int) $passRelease->releases_id;
            Release::whereId($releasesId)->update(['passwordstatus' => 1]);
            Search::updateRelease($releasesId);
            $count++;
        }

        $this->info('Updated '.$count.' bad releases');
    }
}
