<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ObfuscationRecovery\RecoveryStatus;
use Illuminate\Console\Command;

final class ObfuscationRecoveryStatus extends Command
{
    protected $signature = 'obfuscation:recovery-status {--after-group=0} {--after-bundle=0}';

    protected $description = 'Read recovery queues, coverage, retention and lifetime accounting';

    public function handle(RecoveryStatus $status): int
    {
        $this->line(json_encode($status->details((int) $this->option('after-group'), (int) $this->option('after-bundle')), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
