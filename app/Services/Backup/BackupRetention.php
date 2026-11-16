<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Support\SettingNumber;

/**
 * Resolves the two retention counts once, so every reader keeps the same number of backups.
 *
 * Both are retention windows: a blank, missing or non-positive stored value means the seeded default.
 */
final class BackupRetention
{
    public const int LOCAL_DEFAULT = 4;

    public const int OFFSITE_DEFAULT = 7;

    public const int OFFSITE_MAX = 10;

    /** Backups to keep in the Backup location. */
    public static function localKeep(): int
    {
        $keep = SettingNumber::int('backup_keep', self::LOCAL_DEFAULT);

        return $keep > 0 ? $keep : self::LOCAL_DEFAULT;
    }

    /** Off-site backups to keep, from 1 to 10. */
    public static function offsiteKeep(): int
    {
        $keep = SettingNumber::int('backup_offsite_keep', self::OFFSITE_DEFAULT);

        return $keep > 0 ? min($keep, self::OFFSITE_MAX) : self::OFFSITE_DEFAULT;
    }
}
