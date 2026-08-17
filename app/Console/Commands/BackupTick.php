<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Settings;
use App\Services\Backup\BackupAlreadyRunning;
use App\Services\Backup\BackupScheduleService;
use App\Services\Backup\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:tick')]
#[Description('Run the due scheduled or requested database backup')]
class BackupTick extends Command
{
    public function handle(BackupScheduleService $schedule, BackupService $backups): int
    {
        $due = null;

        try {
            $due = $schedule->due();
            if ($due === null) {
                $this->components->info('No database backup is due.');

                return self::SUCCESS;
            }

            $kind = $due['kind'];
            $reason = $due['reason'] === 'requested' ? 'requested' : 'scheduled';
            $this->components->info(sprintf('Running %s %s backup.', $reason, $kind->label()));
            $backups->run($kind);

            return self::SUCCESS;
        } catch (BackupAlreadyRunning $e) {
            if (($due['reason'] ?? null) === 'requested') {
                Settings::settingsUpdate(['backup_run_request' => $due['kind']->value]);
            }

            $this->components->warn($e->getMessage());
            logger()->notice('Database backup tick skipped because another run holds the lock.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
