<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Models\UsenetGroup;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseDeletionProtection;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NntmuxResetTruncate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:reset-truncate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command removes releases with no NZBs, resets all groups, truncates article tables. All other releases are left alone.';

    /**
     * Execute the console command.
     */
    public function handle(
        ReleaseManagementService $releaseManagement,
        NzbService $nzb,
        ReleaseImageService $releaseImage,
    ): void {
        UsenetGroup::query()->update(['first_record' => 0, 'first_record_postdate' => null, 'last_record' => 0, 'last_record_postdate' => null, 'last_updated' => null]);
        $this->info('Reseting all groups completed.');
        $usesMysql = DB::getDriverName() === 'mysql';
        if ($usesMysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        }

        try {
            foreach (['parts', 'missed_parts', 'binaries', 'collections'] as $table) {
                if ($usesMysql) {
                    DB::statement("TRUNCATE TABLE $table");
                } else {
                    DB::table($table)->delete();
                }
                $this->info("Truncating $table completed.");
            }

            $releases = ReleaseDeletionProtection::apply(Release::query())
                ->where('nzbstatus', 0)
                ->get(['id', 'guid']);
            $deletedCount = $releaseManagement->deleteBatchIfUnclaimed($releases, $nzb, $releaseImage);
            $this->info($deletedCount.' releases had no nzb, deleted.');
        } finally {
            if ($usesMysql) {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
            }
        }
    }
}
