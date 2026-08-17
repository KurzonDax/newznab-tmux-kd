<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\BackupAlreadyRunning;
use App\Services\Backup\BackupOffsiteService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:offsite
            {--destination= : Override the configured off-site destination}
            {--keep= : Override the destination retention count; 0 keeps everything}
            {--allow-local : Allow a destination on the same filesystem}
            {--prune : Apply retention even when every file is already copied}')]
#[Description('Copy database Backup sets to verified external or network storage')]
class BackupOffsite extends Command
{
    public function handle(BackupOffsiteService $offsite): int
    {
        $keep = $this->option('keep');
        if ($keep !== null && (! is_numeric($keep) || (int) $keep < 0)) {
            $this->components->error('--keep must be zero or a positive integer.');

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
            '%d %s copied; %d already complete across %d Backup sets.',
            $result['copied'],
            $result['copied'] === 1 ? 'file' : 'files',
            $result['skipped'],
            $result['sets'],
        ));

        return self::SUCCESS;
    }
}
