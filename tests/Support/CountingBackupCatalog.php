<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Backup\BackupCatalog;
use Illuminate\Contracts\Foundation\Application;

/**
 * Counts every checksum the real catalog calculates, so tests can prove
 * existing backups are not hashed again after they were written.
 */
final class CountingBackupCatalog extends BackupCatalog
{
    /** @var list<string> */
    public array $checksummed = [];

    public static function install(Application $app): self
    {
        $catalog = new self;
        $app->instance(BackupCatalog::class, $catalog);

        return $catalog;
    }

    public function checksum(string $path, ?float $deadline = null): string
    {
        $this->checksummed[] = $path;

        return parent::checksum($path, $deadline);
    }
}
