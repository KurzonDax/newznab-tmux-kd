<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Settings;
use App\Services\Tmux\Tmux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BackupPauseManager
{
    public function __construct(private readonly Tmux $tmux) {}

    /**
     * @return array{enabled: bool, prior_running: bool}
     */
    public function pause(string $lockOwner): array
    {
        $enabled = filter_var(Settings::settingValue('backup_pause_tmux'), FILTER_VALIDATE_BOOL);
        $priorRunning = filter_var(Settings::settingValue('running'), FILTER_VALIDATE_BOOL);

        if (! $enabled) {
            return ['enabled' => false, 'prior_running' => $priorRunning];
        }

        Settings::settingsUpdate([
            'backup_pause_marker' => json_encode([
                'paused_at' => Carbon::now()->toIso8601String(),
                'prior_running' => $priorRunning,
                'pid' => getmypid(),
                'process_start_ticks' => $this->processStartTicks(getmypid()),
                'lock_owner' => $lockOwner,
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            $this->tmux->stopIfRunning();
        } catch (\Throwable $e) {
            Settings::settingsUpdate([
                'running' => $priorRunning ? '1' : '0',
                'backup_pause_marker' => '',
            ]);

            throw $e;
        }

        return ['enabled' => true, 'prior_running' => $priorRunning];
    }

    /**
     * @param  array{enabled: bool, prior_running: bool}  $state
     */
    public function restore(array $state): void
    {
        if (! $state['enabled']) {
            return;
        }

        Settings::settingsUpdate([
            'running' => $state['prior_running'] ? '1' : '0',
            'backup_pause_marker' => '',
        ]);
    }

    public function recoverStalePause(): bool
    {
        $rawMarker = (string) Settings::settingValue('backup_pause_marker');
        if ($rawMarker === '') {
            return false;
        }

        try {
            $marker = json_decode($rawMarker, true, flags: JSON_THROW_ON_ERROR);
            $pausedAt = Carbon::parse((string) ($marker['paused_at'] ?? ''));
            $staleAfter = (int) config('nntmux-backup.pause_stale_after_minutes', 120);

            if ($pausedAt->greaterThan(Carbon::now()->subMinutes($staleAfter))) {
                return false;
            }

            if ($this->markerProcessIsAlive($marker)) {
                return false;
            }

            $lockOwner = (string) ($marker['lock_owner'] ?? '');
            if ($lockOwner !== '') {
                Cache::restoreLock('database-backup-run', $lockOwner)->release();
            }

            $lock = Cache::lock('database-backup-run', 60);
            if (! $lock->get()) {
                return false;
            }

            try {
                if ((string) Settings::settingValue('backup_pause_marker') !== $rawMarker) {
                    return false;
                }

                Settings::settingsUpdate([
                    'running' => ! empty($marker['prior_running']) ? '1' : '0',
                    'backup_pause_marker' => '',
                ]);
            } finally {
                $lock->release();
            }

            Log::warning('Recovered stale database Backup pause marker.', ['paused_at' => $pausedAt->toIso8601String()]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Unable to recover database Backup pause marker: '.$e->getMessage());

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $marker
     */
    private function markerProcessIsAlive(array $marker): bool
    {
        $pid = (int) ($marker['pid'] ?? 0);
        if ($pid < 1) {
            return false;
        }

        $currentStartTicks = $this->processStartTicks($pid);
        $expectedStartTicks = (string) ($marker['process_start_ticks'] ?? '');

        if ($currentStartTicks !== null && $expectedStartTicks !== '') {
            return hash_equals($expectedStartTicks, $currentStartTicks);
        }

        if ($currentStartTicks !== null) {
            return true;
        }

        return function_exists('posix_kill') && @posix_kill($pid, 0);
    }

    private function processStartTicks(int $pid): ?string
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return null;
        }

        $closingParenthesis = strrpos($stat, ')');
        if ($closingParenthesis === false) {
            return null;
        }

        $fields = preg_split('/\s+/', trim(substr($stat, $closingParenthesis + 1))) ?: [];

        return isset($fields[19]) ? (string) $fields[19] : null;
    }
}
