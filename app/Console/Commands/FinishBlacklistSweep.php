<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BlacklistSweepService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('blacklist-sweep:finish {run : Sweep run identifier} {--exit-code=0 : Exit code from releases:remove-crap}')]
#[Description('Finalize an admin-triggered blacklist sweep status record')]
class FinishBlacklistSweep extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BlacklistSweepService $sweeps): int
    {
        $sweeps->complete((string) $this->argument('run'), (int) $this->option('exit-code'));

        return self::SUCCESS;
    }
}
