<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ObfuscationRecovery\RecoverySlots;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Console\Command;

final class ObfuscationReclaim extends Command
{
    protected $signature = 'obfuscation:reclaim {kind : slot or work} {id} {token}
        {--confirmed-stopped : The operator has stopped the exact owning worker; settle its slot before its work}';

    protected $description = 'Release one expired recovery lease after an operator confirms its unknown owner has stopped';

    public function handle(RecoverySlots $slots, RecoveryWork $work): int
    {
        $kind = $this->argument('kind');
        $id = (string) $this->argument('id');
        if (! $this->option('confirmed-stopped') || ! in_array($kind, ['slot', 'work'], true)
            || ! ctype_digit($id) || (int) $id < 1) {
            $this->error('Stop the exact owning worker, then supply slot or work, its ID and current token, and --confirmed-stopped. Settle its slot first.');

            return self::INVALID;
        }
        $released = ($kind === 'slot' ? $slots : $work)->confirmStopped((int) $id, (string) $this->argument('token'));
        $this->line($released ? 'reclaimed' : 'unchanged: live owner, unexpired or replaced lease, or unsettled slot');

        return $released ? self::SUCCESS : self::FAILURE;
    }
}
