<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\BackupAlreadyRunning;
use App\Services\Backup\BackupOffsiteService;
use App\Services\Backup\BackupRetention;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:offsite
            {--destination= : Override the configured off-site destination}
            {--keep= : Override the destination retention count, from 1 to 10}
            {--allow-local : Allow a destination on the same filesystem}')]
#[Description('Copy database backups to verified external or network storage')]
class BackupOffsite extends Command
{
    public function handle(BackupOffsiteService $offsite): int
    {
        $keep = $this->option('keep');
        if ($keep !== null && filter_var($keep, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => BackupRetention::OFFSITE_MAX]]) === false) {
            $this->components->error('--keep must be a whole number from 1 to '.BackupRetention::OFFSITE_MAX.'.');

            return self::INVALID;
        }

        try {
            $result = $offsite->copy(
                $this->option('destination') !== null ? (string) $this->option('destination') : null,
                $keep !== null ? (int) $keep : null,
                (bool) $this->option('allow-local'),
            );
        } catch (BackupAlreadyRunning $e) {
            $this->components->warn($e->getMessage());

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%d %s copied; %d already complete across %d backups.',
            $result['copied'],
            $result['copied'] === 1 ? 'file' : 'files',
            $result['skipped'],
            $result['sets'],
        ));

        return self::SUCCESS;
    }
}
