<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Enums\BackupKind;
use App\Models\Settings;
use Illuminate\Support\Carbon;

class BackupScheduleService
{
    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly BackupLocationValidator $locationValidator,
    ) {}

    /**
     * @return array{kind: BackupKind, reason: string}|null
     */
    public function due(): ?array
    {
        $enabled = filter_var(Settings::settingValue('backup_enabled'), FILTER_VALIDATE_BOOL);
        if (! $enabled) {
            if (BackupKind::tryFrom((string) Settings::settingValue('backup_run_request')) !== null) {
                Settings::settingsUpdate(['backup_run_request' => '']);
                logger()->warning('Discarded database backup request because backups are disabled.');
            }

            return null;
        }

        $request = BackupKind::tryFrom((string) Settings::settingValue('backup_run_request'));
        if ($request !== null) {
            Settings::settingsUpdate(['backup_run_request' => '']);

            return ['kind' => $request, 'reason' => 'requested'];
        }

        $location = $this->locationValidator->validate((string) Settings::settingValue('backup_location'));
        $now = Carbon::now((string) config('app.timezone'));
        $fullDay = (int) Settings::settingValue('backup_full_dow');
        $fullSlot = $this->mostRecentSlot($now, $fullDay, (string) Settings::settingValue('backup_full_time'));
        $lastFull = $this->catalog->latestFinishedAt($location, BackupKind::Full->value);

        if ($lastFull === null || $lastFull->lessThan($fullSlot)) {
            return ['kind' => BackupKind::Full, 'reason' => 'scheduled'];
        }

        if ($now->dayOfWeek === $fullDay) {
            return null;
        }

        $dailySlot = $this->mostRecentDailySlot($now, $fullDay, (string) Settings::settingValue('backup_daily_time'));
        $lastDaily = $this->catalog->latestFinishedAt($location, BackupKind::Daily->value);

        if ($lastDaily === null || $lastDaily->lessThan($dailySlot)) {
            return ['kind' => BackupKind::Daily, 'reason' => 'scheduled'];
        }

        return null;
    }

    public function nextRun(?Carbon $from = null): Carbon
    {
        $from ??= Carbon::now((string) config('app.timezone'));
        $fullDay = (int) Settings::settingValue('backup_full_dow');
        $fullTime = (string) Settings::settingValue('backup_full_time');
        $dailyTime = (string) Settings::settingValue('backup_daily_time');

        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $from->copy()->addDays($offset);
            $time = $day->dayOfWeek === $fullDay ? $fullTime : $dailyTime;
            [$hour, $minute] = array_map('intval', explode(':', $time));
            $candidate = $day->copy()->setTime($hour, $minute);

            if ($candidate->greaterThan($from)) {
                return $candidate;
            }
        }

        return $from->copy()->addWeek();
    }

    private function mostRecentSlot(Carbon $now, int $dayOfWeek, string $time): Carbon
    {
        $daysBack = ($now->dayOfWeek - $dayOfWeek + 7) % 7;
        $slot = $now->copy()->subDays($daysBack)->startOfDay();
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $slot->setTime($hour, $minute);

        if ($slot->greaterThan($now)) {
            $slot->subWeek();
        }

        return $slot;
    }

    private function mostRecentDailySlot(Carbon $now, int $fullDay, string $time): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        for ($offset = 0; $offset <= 7; $offset++) {
            $slot = $now->copy()->subDays($offset)->setTime($hour, $minute);
            if ($slot->dayOfWeek !== $fullDay && $slot->lessThanOrEqualTo($now)) {
                return $slot;
            }
        }

        return $now->copy()->subWeek();
    }
}
