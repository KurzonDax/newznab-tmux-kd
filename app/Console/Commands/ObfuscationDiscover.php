<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ObfuscationRecovery\RecoveryPaneText;
use App\Services\ObfuscationRecovery\RecoveryScheduler;
use App\Services\ObfuscationRecovery\RecoveryStage;
use Illuminate\Console\Command;

final class ObfuscationDiscover extends Command
{
    protected $signature = 'obfuscation:discover {--limit=10} {--seconds=60} {--engine}';

    protected $description = 'Run one bounded recovery discover slice';

    public function handle(RecoveryScheduler $scheduler, RecoveryPaneText $text): int
    {
        $text->say($text->title('discover'), 'header');
        $scheduler->local(RecoveryStage::Discover, (int) $this->option('limit'),
            (int) $this->option('seconds'), (bool) $this->option('engine'),
            fn (string $event, array $data) => $text->observe(RecoveryStage::Discover, $event, $data));

        return self::SUCCESS;
    }
}
