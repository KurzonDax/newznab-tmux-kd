<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Enums\BackupKind;
use App\Models\Settings;
use Composer\InstalledVersions;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class BackupService
{
    public function __construct(
        private readonly BackupTableClassifier $tableClassifier,
        private readonly BackupLocationValidator $locationValidator,
        private readonly BackupCatalog $catalog,
        private readonly BackupAlertService $alerts,
        private readonly BackupPauseManager $pauseManager,
        private readonly BackupOffsiteService $offsite,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(BackupKind $kind): array
    {
        $lock = Cache::lock('database-backup-run', (int) config('nntmux-backup.lock_seconds'));
        if (! $lock->get()) {
            throw new BackupAlreadyRunning('A database backup is already running; skipped.');
        }

        $startedAt = Carbon::now();
        $deadline = microtime(true) + (int) config('nntmux-backup.operation_timeout_seconds');

        try {
            return $this->performRun($kind, $deadline, $lock->owner());
        } catch (\Throwable $e) {
            $failedSetId = 'failed-'.$startedAt->format('Ymd-His');
            try {
                $this->catalog->recordFailure($kind->value, $failedSetId, $startedAt, $e->getMessage());
            } catch (\Throwable $catalogError) {
                Log::error('Unable to record database backup failure.', [
                    'backup_error' => $e->getMessage(),
                    'catalog_error' => $catalogError->getMessage(),
                ]);
            }
            $this->alerts->failure($kind, $e->getMessage(), $failedSetId);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function performRun(BackupKind $kind, float $deadline, string $lockOwner): array
    {
        if (! filter_var(Settings::settingValue('backup_enabled'), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Database backups are disabled.');
        }

        $this->ensureSupportedDatabase();
        $location = $this->locationValidator->validate((string) Settings::settingValue('backup_location'));
        $catalogFiles = $this->catalog->files($location, $deadline);
        $this->ensureFreeSpace($location, $kind, $catalogFiles);
        $this->ensureBeforeDeadline($deadline);
        $dumpBinary = $this->resolveDumpBinary();
        $compressor = $this->resolveCompressor();
        $includeWorking = filter_var(Settings::settingValue('backup_incl_working'), FILTER_VALIDATE_BOOL);
        $selection = $this->tableClassifier->tablesFor($kind, $includeWorking);

        if ($selection['tables'] === []) {
            throw new RuntimeException('No database tables were selected for backup.');
        }

        $startedAt = Carbon::now();
        $setId = $kind === BackupKind::Full
            ? $startedAt->format('Ymd-His')
            : $this->latestSetId($catalogFiles, $startedAt);
        $setDirectory = $location.DIRECTORY_SEPARATOR.$setId;

        if (! is_dir($setDirectory) && ! mkdir($setDirectory, 0o775, true) && ! is_dir($setDirectory)) {
            throw new RuntimeException("Unable to create Backup set directory: {$setDirectory}");
        }

        $filename = sprintf('%s-%s.sql.gz', $kind->value, $startedAt->format('Ymd-Hi'));
        $dumpPath = $setDirectory.DIRECTORY_SEPARATOR.$filename;
        $partialPath = $dumpPath.'.partial';
        $manifestPath = $dumpPath.'.manifest.json';
        $pauseState = $this->pauseManager->pause($lockOwner);
        $manifest = null;

        try {
            $this->runDump(
                $dumpBinary,
                $compressor,
                $partialPath,
                $selection['tables'],
                $this->remainingProcessSeconds($deadline),
            );
            $this->verifyGzip($compressor, $partialPath, $this->remainingProcessSeconds($deadline));
            $this->ensureBeforeDeadline($deadline);

            if (! rename($partialPath, $dumpPath)) {
                throw new RuntimeException('Unable to finalize backup file.');
            }

            $finishedAt = Carbon::now();
            $manifest = [
                'kind' => $kind->value,
                'started_at' => $startedAt->toIso8601String(),
                'finished_at' => $finishedAt->toIso8601String(),
                'tables' => $selection['tables'],
                'tiers_included' => $selection['tiers'],
                'bytes' => filesize($dumpPath),
                'sha256' => $this->catalog->checksum($dumpPath, $deadline),
                'app_version' => config('app.version') ?: (InstalledVersions::getRootPackage()['pretty_version'] ?? 'unknown'),
                'db_server_version' => (string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
                'set_id' => $setId,
            ];

            $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            if (file_put_contents($manifestPath.'.partial', $encoded, LOCK_EX) === false
                || ! rename($manifestPath.'.partial', $manifestPath)) {
                throw new RuntimeException('Unable to write backup manifest.');
            }

            $this->catalog->recordSuccess($manifestPath, $manifest);
        } catch (\Throwable $e) {
            @unlink($partialPath);
            @unlink($dumpPath);
            @unlink($manifestPath.'.partial');
            @unlink($manifestPath);
            @rmdir($setDirectory);

            throw $e;
        } finally {
            $this->pauseManager->restore($pauseState);
        }

        if ($kind === BackupKind::Full) {
            $this->catalog->purgeOldSets($location, (int) Settings::settingValue('backup_keep_fulls'), $deadline);
            $this->ensureBeforeDeadline($deadline);
        }

        if (filter_var(Settings::settingValue('backup_offsite_after'), FILTER_VALIDATE_BOOL)
            && trim((string) Settings::settingValueOr('backup_offsite_path', '')) !== '') {
            try {
                $this->offsite->copy(null, null, false, $deadline);
            } catch (\Throwable $e) {
                Log::error('Automatic off-site copy failed after successful database backup.', ['error' => $e->getMessage()]);
            }
        }

        return $manifest;
    }

    private function ensureSupportedDatabase(): void
    {
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Database backups are unsupported for the '{$driver}' driver.");
        }
    }

    /**
     * @param  list<array{manifest: array<string, mixed>, verified: bool}>  $catalogFiles
     */
    private function ensureFreeSpace(string $location, BackupKind $kind, array $catalogFiles): void
    {
        $previous = $this->latestManifestForKind($catalogFiles, $kind->value);
        if ($previous === null || (int) ($previous['bytes'] ?? 0) <= 0) {
            return;
        }

        $available = disk_free_space($location);
        if ($available === false) {
            throw new RuntimeException('Unable to determine free space at the Backup location.');
        }

        $required = (float) $previous['bytes'] * (float) config('nntmux-backup.free_space_multiplier', 2);
        if ((float) $available < $required) {
            throw new RuntimeException(sprintf(
                'Insufficient free space for %s backup: %.0f bytes available, %.0f required.',
                $kind->label(),
                $available,
                $required,
            ));
        }
    }

    private function resolveDumpBinary(): string
    {
        $override = trim((string) Settings::settingValue('backup_dump_binary'));
        if ($override !== '') {
            if (! str_starts_with($override, DIRECTORY_SEPARATOR) || ! is_executable($override)) {
                throw new RuntimeException('Configured backup dump binary does not exist or is not executable.');
            }

            return $override;
        }

        $result = Process::run(['sh', '-c', 'command -v mariadb-dump || command -v mysqldump']);
        $binary = trim($result->output());

        if (! $result->successful() || $binary === '') {
            throw new RuntimeException('Neither mariadb-dump nor mysqldump is available.');
        }

        return $binary;
    }

    private function resolveCompressor(): string
    {
        $result = Process::run(['sh', '-c', 'command -v pigz || command -v gzip']);
        $compressor = trim($result->output());

        if (! $result->successful() || $compressor === '') {
            throw new RuntimeException('Neither pigz nor gzip is available.');
        }

        return $compressor;
    }

    /**
     * @param  list<string>  $tables
     */
    private function runDump(
        string $binary,
        string $compressor,
        string $outputPath,
        array $tables,
        int $timeout,
    ): void {
        $connection = (string) config('database.default');
        $database = (array) config("database.connections.{$connection}");
        $script = '"$BACKUP_DUMP_BINARY" --single-transaction --quick --routines --triggers --skip-lock-tables'.
            ' --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" "$DB_DATABASE" "$@"'.
            ' | "$BACKUP_COMPRESSOR" > "$BACKUP_OUTPUT"';

        $result = Process::env([
            'BACKUP_DUMP_BINARY' => $binary,
            'BACKUP_COMPRESSOR' => $compressor,
            'BACKUP_OUTPUT' => $outputPath,
            'DB_HOST' => (string) ($database['host'] ?? '127.0.0.1'),
            'DB_PORT' => (string) ($database['port'] ?? 3306),
            'DB_USERNAME' => (string) ($database['username'] ?? ''),
            'DB_DATABASE' => (string) ($database['database'] ?? ''),
            'MYSQL_PWD' => (string) ($database['password'] ?? ''),
        ])->timeout($timeout)
            ->run(['bash', '-o', 'pipefail', '-c', $script, 'backup-dump', ...$tables]);

        $this->ensureSuccessful($result, 'Database dump failed');

        if (! is_file($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException('Database dump produced no output.');
        }
    }

    private function verifyGzip(string $compressor, string $path, int $timeout): void
    {
        $this->ensureSuccessful(
            Process::timeout($timeout)->run([$compressor, '-t', $path]),
            'Backup gzip verification failed',
        );
    }

    private function ensureSuccessful(ProcessResult $result, string $message): void
    {
        if (! $result->successful()) {
            $detail = trim($result->errorOutput());
            throw new RuntimeException($detail === '' ? $message : "{$message}: {$detail}");
        }
    }

    /**
     * @param  list<array{manifest: array<string, mixed>, verified: bool}>  $catalogFiles
     */
    private function latestSetId(array $catalogFiles, Carbon $now): string
    {
        $manifest = $this->latestManifestForKind($catalogFiles, BackupKind::Full->value);
        if ($manifest === null) {
            return 'no-full-yet-'.$now->format('Ymd-His');
        }

        $setId = (string) ($manifest['set_id'] ?? '');
        if ($setId === '' || basename($setId) !== $setId) {
            throw new RuntimeException('The latest Full backup has an unsafe Backup set identifier.');
        }

        return $setId;
    }

    /**
     * @param  list<array{manifest: array<string, mixed>, verified: bool}>  $catalogFiles
     * @return array<string, mixed>|null
     */
    private function latestManifestForKind(array $catalogFiles, string $kind): ?array
    {
        foreach ($catalogFiles as $file) {
            if (($file['manifest']['kind'] ?? null) === $kind && ($file['verified'] ?? false)) {
                return $file['manifest'];
            }
        }

        return null;
    }

    private function remainingProcessSeconds(float $deadline): int
    {
        $remaining = (int) floor($deadline - microtime(true) - 3600);
        if ($remaining < 1) {
            throw new RuntimeException('Database backup stopped before its operation deadline.');
        }

        return min((int) config('nntmux-backup.process_timeout_seconds'), $remaining);
    }

    private function ensureBeforeDeadline(float $deadline): void
    {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Database backup exceeded its operation deadline.');
        }
    }
}
