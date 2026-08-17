<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ForkingService;
use Illuminate\Console\Command;

class ProcessSafe extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'multiprocessing:safe
                            {type : Type: binaries}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safe binaries update using multiprocessing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->argument('type');

        if ($type !== 'binaries') {
            $this->error('Type must be: binaries');
            $this->line('');
            $this->line('binaries => Do Safe Binaries update');

            return self::FAILURE;
        }

        try {
            $service = new ForkingService;

            $service->safeBinaries();

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
