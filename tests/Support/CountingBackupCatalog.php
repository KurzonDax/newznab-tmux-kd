<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Backup\BackupCatalog;

/**
 * Counts every checksum the real catalog calculates, so tests can prove
 * existing backups are not hashed again after they were written.
 */
final class CountingBackupCatalog extends BackupCatalog
{
    /** @var list<string> */
    public array $checksummed = [];

    public function checksum(string $path, ?float $deadline = null): string
    {
        $this->checksummed[] = $path;

        return parent::checksum($path, $deadline);
    }
}
