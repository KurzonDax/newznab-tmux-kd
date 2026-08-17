<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Settings;
use App\Services\Backup\BackupCatalog;
use App\Services\Backup\BackupLocationValidator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:list')]
#[Description('List database Backup sets and verify their checksums')]
class BackupList extends Command
{
    public function handle(BackupCatalog $catalog, BackupLocationValidator $validator): int
    {
        try {
            $location = $validator->validate((string) Settings::settingValue('backup_location'));
            $sets = $catalog->reconcile($location);
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($sets === []) {
            $this->components->info('No database Backup sets found.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($sets as $set) {
            foreach ($set['files'] as $file) {
                $manifest = $file['manifest'];
                $rows[] = [
                    $set['set_id'],
                    (string) ($manifest['kind'] ?? 'unknown'),
                    (string) ($manifest['finished_at'] ?? 'unknown'),
                    $this->formatBytes((int) ($manifest['bytes'] ?? 0)),
                    $file['verified'] ? 'verified' : 'FAILED',
                ];
            }
        }

        $this->table(['Backup set', 'Kind', 'Finished', 'Size', 'Checksum'], $rows);

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        return number_format($bytes / 1024, 1).' KiB';
    }
}
