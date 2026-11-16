<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Settings;
use Illuminate\Support\Carbon;

class BackupScheduleService
{
    private const string DEFAULT_BACKUP_TIME = '02:00';

    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly BackupLocationValidator $locationValidator,
    ) {}

    /**
     * Whether a backup is due now: 'requested' for a pending Run backup now, 'scheduled' when no
     * attempt has finished since the most recent Backup-time slot, or null.
     */
    public function due(): ?string
    {
        $requested = trim((string) Settings::settingValue('backup_run_request')) !== '';
        $enabled = filter_var(Settings::settingValue('backup_enabled'), FILTER_VALIDATE_BOOL);
        if (! $enabled) {
            if ($requested) {
                Settings::settingsUpdate(['backup_run_request' => '']);
                logger()->warning('Discarded database backup request because backups are disabled.');
            }

            return null;
        }

        // Any non-empty value is a request, including a full or daily value left from before ADR 0016.
        if ($requested) {
            Settings::settingsUpdate(['backup_run_request' => '']);

            return 'requested';
        }

        $location = $this->locationValidator->validate((string) Settings::settingValue('backup_location'));
        $slot = $this->mostRecentSlot(Carbon::now((string) config('app.timezone')));
        $lastAttempt = $this->catalog->latestAttemptFinishedAt($location);

        // A failed run counts as the slot's attempt, so it is not retried until the next slot.
        if ($lastAttempt === null || $lastAttempt->lessThan($slot)) {
            return 'scheduled';
        }

        return null;
    }

    public function nextRun(?Carbon $from = null): Carbon
    {
        $from ??= Carbon::now((string) config('app.timezone'));
        $slot = $this->slotOn($from);

        return $slot->greaterThan($from) ? $slot : $this->slotOn($from->copy()->addDay());
    }

    private function mostRecentSlot(Carbon $now): Carbon
    {
        $slot = $this->slotOn($now);

        return $slot->greaterThan($now) ? $this->slotOn($now->copy()->subDay()) : $slot;
    }

    private function slotOn(Carbon $day): Carbon
    {
        $time = (string) Settings::settingValue('backup_time');
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            $time = self::DEFAULT_BACKUP_TIME;
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $day->copy()->setTime($hour, $minute);
    }
}
