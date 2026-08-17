<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BackupKind;
use App\Services\Backup\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:run {kind : full or daily}')]
#[Description('Run a Full or Daily database backup immediately')]
class BackupRun extends Command
{
    public function handle(BackupService $backupService): int
    {
        $kind = BackupKind::tryFrom(strtolower((string) $this->argument('kind')));
        if ($kind === null) {
            $this->components->error('Backup kind must be full or daily.');

            return self::INVALID;
        }

        try {
            $manifest = $backupService->run($kind);
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s backup completed (%s, sha256 %s).',
            $kind->label(),
            number_format((int) $manifest['bytes']).' bytes',
            $manifest['sha256'],
        ));

        return self::SUCCESS;
    }
}
