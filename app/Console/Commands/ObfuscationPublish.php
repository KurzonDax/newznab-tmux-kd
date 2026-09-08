<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\ObfuscationRecovery\RecoveryNzbRestore;
use App\Services\ObfuscationRecovery\RecoveryScheduler;
use App\Services\ObfuscationRecovery\RecoveryStage;
use Illuminate\Console\Command;

final class ObfuscationPublish extends Command
{
    protected $signature = 'obfuscation:publish {--limit=10} {--seconds=60} {--engine} {--restore=0 : Restore exact cached NZB membership for a release}';

    protected $description = 'Run one bounded recovery publish slice';

    public function handle(RecoveryScheduler $scheduler): int
    {
        if ((int) $this->option('restore') > 0) {
            $release = Release::query()->find((int) $this->option('restore'));
            $this->line($release === null ? 'release_missing' : app(RecoveryNzbRestore::class)->run($release));

            return self::SUCCESS;
        }
        $this->line(json_encode($scheduler->local(RecoveryStage::Publish, (int) $this->option('limit'),
            (int) $this->option('seconds'), (bool) $this->option('engine')), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
