<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BackupKind;
use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\UpdateBackupSettingsRequest;
use App\Models\DatabaseBackup;
use App\Models\Settings;
use App\Services\Backup\BackupCatalog;
use App\Services\Backup\BackupLocationValidator;
use App\Services\Backup\BackupScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AdminBackupsController extends BasePageController
{
    public function index(
        BackupCatalog $catalog,
        BackupLocationValidator $locationValidator,
        BackupScheduleService $schedule,
    ): View {
        $this->setAdminPrefs();
        $settings = Settings::query()->pluck('value', 'name');
        $sets = [];
        $locationError = null;

        try {
            $location = $locationValidator->validate((string) $settings->get('backup_location', ''));
            $sets = $catalog->reconcile($location);
            $sets = $this->withOffsiteStatus($sets);
        } catch (\Throwable $e) {
            $locationError = $e->getMessage();
        }

        $enabled = filter_var($settings->get('backup_enabled'), FILTER_VALIDATE_BOOL);
        $driver = (string) config('database.connections.'.config('database.default').'.driver');
        $this->viewData = array_merge($this->viewData, [
            'title' => 'Database Backups',
            'meta_title' => 'Database Backups',
            'site' => $settings,
            'sets' => $sets,
            'locationError' => $locationError,
            'supported' => in_array($driver, ['mysql', 'mariadb'], true),
            'databaseDriver' => $driver,
            'nextRun' => $enabled ? $schedule->nextRun() : null,
            'lastFull' => $this->lastResult(BackupKind::Full),
            'lastDaily' => $this->lastResult(BackupKind::Daily),
        ]);

        return view('admin.backups.index', $this->viewData);
    }

    public function update(UpdateBackupSettingsRequest $request): RedirectResponse
    {
        $settings = $request->validated();
        $settings['backup_dump_binary'] = trim((string) ($settings['backup_dump_binary'] ?? ''));
        $settings['backup_offsite_path'] = trim((string) ($settings['backup_offsite_path'] ?? ''));
        $settings['backup_offsite_keep'] = (string) ($settings['backup_offsite_keep'] ?? 0);

        Settings::settingsUpdate($settings);

        return redirect()->route('admin.backups.index')->with('success', 'Database backup settings updated successfully.');
    }

    public function run(string $kind): RedirectResponse
    {
        $backupKind = BackupKind::tryFrom($kind);
        if ($backupKind === null) {
            abort(404);
        }

        if (! filter_var(Settings::settingValue('backup_enabled'), FILTER_VALIDATE_BOOL)) {
            return redirect()->route('admin.backups.index')->with(
                'error',
                'Enable database backups before requesting a run.',
            );
        }

        $pendingKind = BackupKind::tryFrom((string) Settings::settingValue('backup_run_request'));
        $requestedKind = $pendingKind === BackupKind::Full ? BackupKind::Full : $backupKind;
        Settings::settingsUpdate(['backup_run_request' => $requestedKind->value]);

        return redirect()->route('admin.backups.index')->with(
            'success',
            $requestedKind->label().' backup requested; it will start within one minute.',
        );
    }

    private function lastResult(BackupKind $kind): ?DatabaseBackup
    {
        return DatabaseBackup::query()
            ->where('kind', $kind->value)
            ->latest('finished_at')
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $sets
     * @return list<array<string, mixed>>
     */
    private function withOffsiteStatus(array $sets): array
    {
        $statuses = DatabaseBackup::query()
            ->whereIn('set_id', array_column($sets, 'set_id'))
            ->get(['set_id', 'offsite_status'])
            ->groupBy('set_id');

        return array_map(static function (array $set) use ($statuses): array {
            $records = $statuses->get($set['set_id'], collect());
            $setStatuses = $records->pluck('offsite_status');
            $copied = $setStatuses->filter(static fn (?string $status): bool => $status === 'copied')->count();

            $set['offsite_status'] = match (true) {
                $setStatuses->contains('failed') => 'Failed',
                $copied > 0 && $copied < $records->count() => 'Partial',
                $copied > 0 => 'Copied',
                default => 'Not copied',
            };

            return $set;
        }, $sets);
    }
}
