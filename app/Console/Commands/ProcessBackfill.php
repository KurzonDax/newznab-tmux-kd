<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ForkingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessBackfill extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'multiprocessing:backfill';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill all backfill-enabled groups using multiprocessing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            (new ForkingService)->backfill();

            return self::SUCCESS;
        } catch (\Exception $e) {
            Log::error($e->getTraceAsString());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
