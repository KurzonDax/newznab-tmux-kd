<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:run')]
#[Description('Run a database backup immediately')]
class BackupRun extends Command
{
    public function handle(BackupService $backupService): int
    {
        try {
            $manifest = $backupService->run();
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Backup completed (%s, sha256 %s).',
            number_format((int) $manifest['bytes']).' bytes',
            $manifest['sha256'],
        ));

        return self::SUCCESS;
    }
}
