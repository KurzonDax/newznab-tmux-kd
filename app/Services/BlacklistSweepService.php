<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

final class BlacklistSweepService
{
    private const RETAINED_RUNS = 20;

    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? storage_path('app/blacklist-sweeps');
    }

    /**
     * @return array{id:string, started_at:string, mode:string, rule_id:int|null, pid:int, finished_at:string|null, exit_code:int|null, matched_count:int, removed_count:int, log_path:string, running:bool}
     */
    public function start(string $mode, ?int $ruleId = null): array
    {
        if (! in_array($mode, ['dry-run', 'delete'], true)) {
            throw new RuntimeException('Sweep mode must be dry-run or delete.');
        }

        return $this->withLock(function () use ($mode, $ruleId): array {
            $statuses = $this->recoverOrphanedRuns($this->readStatuses());
            $hasRunningSweep = collect($statuses)->contains(
                static fn (array $status): bool => ($status['running'] ?? false) === true,
            );
            if ($hasRunningSweep) {
                throw new RuntimeException('A blacklist sweep is already running.');
            }

            $id = now()->format('Ymd-His-u').'-'.Str::lower(Str::random(8));
            $logPath = $this->directory.'/'.$id.'.log';
            $status = [
                'id' => $id,
                'started_at' => now()->toIso8601String(),
                'mode' => $mode,
                'rule_id' => $ruleId,
                'pid' => 0,
                'finished_at' => null,
                'exit_code' => null,
                'matched_count' => 0,
                'removed_count' => 0,
                'log_path' => $logPath,
                'running' => true,
            ];
            $this->writeStatus($status);
            touch($logPath);

            $result = Process::timeout(5)->run($this->detachedCommand($status));
            $pid = (int) trim($result->output());
            if (! $result->successful() || $pid < 1) {
                $status['running'] = false;
                $status['finished_at'] = now()->toIso8601String();
                $status['exit_code'] = $result->exitCode();
                $this->writeStatus($status);

                throw new RuntimeException('Unable to launch the blacklist sweep process.');
            }

            $status['pid'] = $pid;
            $this->writeStatus($status);
            $this->prune();

            return $status;
        });
    }

    /**
     * @return array{running:bool, current:array<string, mixed>|null, last:array<string, mixed>|null}
     */
    public function status(): array
    {
        return $this->withLock(function (): array {
            $statuses = array_map(
                fn (array $status): array => $this->withCounts($status),
                $this->recoverOrphanedRuns($this->readStatuses()),
            );
            $current = collect($statuses)->firstWhere('running', true);
            $last = collect($statuses)->firstWhere('running', false);

            return ['running' => $current !== null, 'current' => $current, 'last' => $last];
        });
    }

    /**
     * @return array{running:bool, current:array<string, mixed>|null, last:array<string, mixed>|null}
     */
    public function publicStatus(): array
    {
        $status = $this->status();
        foreach (['current', 'last'] as $key) {
            if (is_array($status[$key])) {
                unset($status[$key]['log_path']);
            }
        }

        return $status;
    }

    public function complete(string $id, int $exitCode): void
    {
        $this->withLock(function () use ($id, $exitCode): void {
            $status = $this->readStatus($id);
            if ($status === null) {
                throw new RuntimeException('Blacklist sweep status not found.');
            }

            $status = $this->withCounts($status);
            $status['running'] = false;
            $status['finished_at'] = now()->toIso8601String();
            $status['exit_code'] = $exitCode;
            $this->writeStatus($status);
            $this->prune();
        });
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function detachedCommand(array $status): string
    {
        $php = escapeshellarg(PHP_BINDIR.DIRECTORY_SEPARATOR.'php');
        $artisan = escapeshellarg(base_path('artisan'));
        $remove = $php.' '.$artisan.' releases:remove-crap --type=blacklist --time=full';
        if ($status['rule_id'] !== null) {
            $remove .= ' --blacklist-id='.(int) $status['rule_id'];
        }
        if ($status['mode'] === 'delete') {
            $remove .= ' --delete';
        }

        $log = escapeshellarg((string) $status['log_path']);
        $finish = $php.' '.$artisan.' blacklist-sweep:finish '.escapeshellarg((string) $status['id']).' --exit-code="$exit_code"';
        $worker = $remove.' > '.$log.' 2>&1; exit_code=$?; '.$finish.' >> '.$log.' 2>&1';

        return 'nohup sh -c '.escapeshellarg($worker).' >/dev/null 2>&1 & echo $!';
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function withCounts(array $status): array
    {
        $log = is_file((string) $status['log_path']) ? (string) file_get_contents((string) $status['log_path']) : '';
        $log = preg_replace('/\x1B(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $log) ?? $log;

        $matched = preg_match_all('/^Would be deleting:/m', $log) ?: 0;
        $removed = preg_match_all('/^Deleting:/m', $log) ?: 0;
        if (preg_match('/Would have deleted\s+([\d,]+)\s+release/', $log, $match) === 1) {
            $matched = (int) str_replace(',', '', $match[1]);
        }
        if (preg_match('/Deleted\s+([\d,]+)\s+release/', $log, $match) === 1) {
            $removed = (int) str_replace(',', '', $match[1]);
            $matched = $removed;
        }

        $status['matched_count'] = $matched;
        $status['removed_count'] = $removed;

        return $status;
    }

    /**
     * Mark runs whose detached worker no longer exists as failed so they cannot block every future sweep.
     *
     * @param  list<array<string, mixed>>  $statuses
     * @return list<array<string, mixed>>
     */
    private function recoverOrphanedRuns(array $statuses): array
    {
        foreach ($statuses as &$status) {
            if (($status['running'] ?? false) !== true || $this->processIsRunning((int) ($status['pid'] ?? 0))) {
                continue;
            }

            $status = $this->withCounts($status);
            $status['running'] = false;
            $status['finished_at'] = now()->toIso8601String();
            $status['exit_code'] = 255;
            $this->writeStatus($status);
        }
        unset($status);

        return $statuses;
    }

    private function processIsRunning(int $pid): bool
    {
        if ($pid < 1) {
            return false;
        }

        if (! function_exists('posix_kill')) {
            return true;
        }

        if (posix_kill($pid, 0)) {
            return true;
        }

        return function_exists('posix_get_last_error') && posix_get_last_error() === 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readStatuses(): array
    {
        $this->ensureDirectory();
        $files = glob($this->directory.'/*.json') ?: [];
        rsort($files, SORT_STRING);
        $statuses = [];
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $statuses[] = $decoded;
            }
        }

        return $statuses;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readStatus(string $id): ?array
    {
        $path = $this->directory.'/'.$id.'.json';
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function writeStatus(array $status): void
    {
        $this->ensureDirectory();
        $json = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($this->directory.'/'.$status['id'].'.json', $json.PHP_EOL, LOCK_EX);
    }

    private function prune(): void
    {
        $files = glob($this->directory.'/*.json') ?: [];
        rsort($files, SORT_STRING);
        foreach (array_slice($files, self::RETAINED_RUNS) as $statusPath) {
            $id = pathinfo($statusPath, PATHINFO_FILENAME);
            @unlink($statusPath);
            @unlink($this->directory.'/'.$id.'.log');
        }
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Unable to create the blacklist sweep status directory.');
        }
    }

    private function withLock(callable $callback): mixed
    {
        $this->ensureDirectory();
        $handle = fopen($this->directory.'/.lock', 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to acquire the blacklist sweep lock.');
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
