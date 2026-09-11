<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ReleaseProcessingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('releases:finalize')]
#[Description('Finish pending NZB publication and global cleanup after group formation')]
class FinalizeReleasesCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ReleaseProcessingService $processing): int
    {
        try {
            $limit = $processing->getReleaseCreationLimit();
            do {
                $added = $processing->createNZBs(null);
            } while ($added >= $limit);
            $processing->deleteCollections(null);
            $processing->deletedReleasesByGroup();
            $processing->deleteReleases();
            $processing->categorizeReleases(2);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error($exception->getMessage(), ['exception' => $exception]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
