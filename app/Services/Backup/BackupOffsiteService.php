<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\DatabaseBackup;
use App\Models\Settings;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class BackupOffsiteService
{
    private ?string $activeSetId = null;

    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly BackupLocationValidator $locationValidator,
        private readonly BackupAlertService $alerts,
    ) {}

    /**
     * @return array{copied: int, skipped: int, sets: int}
     */
    public function copy(
        ?string $destinationOverride,
        ?int $keepOverride,
        bool $allowLocal,
        ?float $deadlineOverride = null,
    ): array {
        $this->activeSetId = null;
        $lockSeconds = (int) config('nntmux-backup.offsite_lock_seconds');
        $lock = Cache::lock('database-backup-offsite', $lockSeconds);
        if (! $lock->get()) {
            throw new BackupAlreadyRunning('An off-site copy is already running; skipped.');
        }

        try {
            $operationDeadline = microtime(true) + (int) config('nntmux-backup.offsite_operation_timeout_seconds');

            return $this->performCopy(
                $destinationOverride,
                $keepOverride,
                $allowLocal,
                $deadlineOverride === null ? $operationDeadline : min($deadlineOverride, $operationDeadline),
            );
        } catch (\Throwable $e) {
            $this->alerts->offsiteFailure($e->getMessage(), $this->activeSetId);

            throw $e;
        } finally {
            $this->activeSetId = null;
            $lock->release();
        }
    }

    /**
     * @return array{copied: int, skipped: int, sets: int}
     */
    private function performCopy(
        ?string $destinationOverride,
        ?int $keepOverride,
        bool $allowLocal,
        float $deadline,
    ): array {
        $this->ensureBeforeDeadline($deadline);
        $source = $this->locationValidator->validate((string) Settings::settingValue('backup_location'));
        $destination = $this->validateDestination(
            $destinationOverride ?: (string) Settings::settingValue('backup_offsite_path'),
            $source,
            $allowLocal,
        );
        $rsync = $this->resolveRsync();
        $sourceFiles = $this->catalog->files($source, $deadline);
        $this->ensureBeforeDeadline($deadline);
        $this->catalog->reconcile($source, $sourceFiles);
        $files = array_reverse($sourceFiles);
        $copied = 0;
        $skipped = 0;
        $sets = [];

        foreach ($files as $file) {
            $setId = (string) ($file['manifest']['set_id'] ?? '');
            if ($setId === '' || basename($setId) !== $setId) {
                throw new RuntimeException('Source manifest contains an unsafe Backup set identifier.');
            }

            $sets[$setId][] = $file;
        }

        foreach ($sets as $setId => $setFiles) {
            $this->activeSetId = $setId;
            $this->markSet($setId, 'copying');

            try {
                foreach ($setFiles as $file) {
                    if ($this->copyFile($file, $destination, $rsync, $setId, $deadline)) {
                        $copied++;
                    } else {
                        $skipped++;
                    }
                }

                $this->markSet($setId, 'copied');
            } catch (\Throwable $e) {
                $this->markSet($setId, 'failed', $e->getMessage());

                throw $e;
            }
        }

        $keep = $keepOverride ?? (int) Settings::settingValue('backup_offsite_keep');
        if ($keep > 0) {
            $this->pruneDestination($destination, $keep, $deadline);
            $this->ensureBeforeDeadline($deadline);
        }

        return ['copied' => $copied, 'skipped' => $skipped, 'sets' => count($sets)];
    }

    /**
     * @param  array{manifest_path: string, dump_path: string, manifest: array<string, mixed>, verified: bool}  $file
     */
    private function copyFile(
        array $file,
        string $destination,
        string $rsync,
        string $setId,
        float $deadline,
    ): bool {
        if (! $file['verified']) {
            throw new RuntimeException("Source checksum verification failed for {$setId}/".basename($file['dump_path']));
        }

        $setDirectory = $destination.DIRECTORY_SEPARATOR.$setId;
        if (! is_dir($setDirectory) && ! mkdir($setDirectory, 0o775, true) && ! is_dir($setDirectory)) {
            throw new RuntimeException("Unable to create off-site Backup set directory: {$setDirectory}");
        }

        $destinationDump = $setDirectory.DIRECTORY_SEPARATOR.basename($file['dump_path']);
        $destinationManifest = $destinationDump.'.manifest.json';
        $checksum = (string) ($file['manifest']['sha256'] ?? '');
        $bytes = (int) ($file['manifest']['bytes'] ?? 0);

        if ($this->isComplete(
            $destinationDump,
            $destinationManifest,
            $file['manifest_path'],
            $checksum,
            $bytes,
            $deadline,
        )) {
            return false;
        }

        @unlink($destinationManifest);
        $temporaryDump = $setDirectory.DIRECTORY_SEPARATOR.'.tmp-'.basename($file['dump_path']);
        $temporaryManifest = $setDirectory.DIRECTORY_SEPARATOR.'.tmp-'.basename($file['manifest_path']);

        try {
            $remainingOperationSeconds = (int) floor($deadline - microtime(true) - 60);
            if ($remainingOperationSeconds < 1) {
                throw new RuntimeException('Off-site copy stopped before its operation deadline.');
            }

            $timeout = min(
                (int) config('nntmux-backup.offsite_process_timeout_seconds'),
                $remainingOperationSeconds,
            );
            $result = Process::env([
                'OFFSITE_SOURCE' => $file['dump_path'],
                'OFFSITE_TEMP' => $temporaryDump,
            ])->timeout($timeout)
                ->run([$rsync, '--partial', '--inplace', $file['dump_path'], $temporaryDump]);

            if (! $result->successful()) {
                throw new RuntimeException('Off-site rsync failed: '.trim($result->errorOutput()));
            }

            if (! is_file($temporaryDump)
                || $checksum === ''
                || ! hash_equals($checksum, $this->catalog->checksum($temporaryDump, $deadline))) {
                @unlink($temporaryDump);
                throw new RuntimeException("Off-site checksum verification failed for {$setId}/".basename($file['dump_path']));
            }

            $this->ensureBeforeDeadline($deadline);
            @unlink($destinationDump);
            if (! rename($temporaryDump, $destinationDump)) {
                throw new RuntimeException('Unable to finalize off-site backup file.');
            }

            if (! copy($file['manifest_path'], $temporaryManifest)) {
                throw new RuntimeException('Unable to copy off-site manifest.');
            }
            $this->ensureBeforeDeadline($deadline);
            if (! rename($temporaryManifest, $destinationManifest)) {
                throw new RuntimeException('Unable to finalize off-site manifest.');
            }
        } catch (\Throwable $e) {
            @unlink($temporaryManifest);

            throw $e;
        }

        return true;
    }

    private function validateDestination(string $path, string $source, bool $allowLocal): string
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Off-site destination must be an absolute path.');
        }

        $destination = realpath($path);
        if ($destination === false || ! is_dir($destination) || ! is_writable($destination)) {
            throw new RuntimeException('Off-site destination must be an existing writable directory.');
        }

        if ($destination === $source
            || str_starts_with($destination, $source.DIRECTORY_SEPARATOR)
            || str_starts_with($source, $destination.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Off-site destination must be separate from the Backup location.');
        }

        $sourceStat = stat($source);
        $destinationStat = stat($destination);
        if (! $allowLocal && ($sourceStat === false || $destinationStat === false || $sourceStat['dev'] === $destinationStat['dev'])) {
            throw new RuntimeException('Off-site destination is not on a separately mounted filesystem; use --allow-local only for a genuine second local disk.');
        }

        $probe = $destination.DIRECTORY_SEPARATOR.'.nntmux-backup-probe-'.bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(32));
        try {
            if (file_put_contents($probe, $token, LOCK_EX) === false
                || file_get_contents($probe) !== $token) {
                throw new RuntimeException('Off-site destination write/read-back probe failed.');
            }
        } finally {
            @unlink($probe);
        }

        return $destination;
    }

    private function resolveRsync(): string
    {
        $result = Process::run(['sh', '-c', 'command -v rsync']);
        $binary = trim($result->output());
        if (! $result->successful() || $binary === '') {
            throw new RuntimeException('rsync is required for off-site copies.');
        }

        return $binary;
    }

    private function isComplete(
        string $dump,
        string $manifest,
        string $sourceManifest,
        string $checksum,
        int $bytes,
        float $deadline,
    ): bool {
        if (! is_file($dump) || ! is_file($manifest) || ! is_file($sourceManifest)) {
            return false;
        }

        $sourceManifestChecksum = $this->catalog->checksum($sourceManifest, $deadline);
        $destinationManifestChecksum = $this->catalog->checksum($manifest, $deadline);

        return filesize($dump) === $bytes
            && $checksum !== ''
            && hash_equals($sourceManifestChecksum, $destinationManifestChecksum)
            && hash_equals($checksum, $this->catalog->checksum($dump, $deadline));
    }

    private function markSet(string $setId, string $status, ?string $error = null): void
    {
        DatabaseBackup::query()->where('set_id', $setId)->update([
            'offsite_status' => $status,
            'offsite_at' => now(),
            'error' => $status === 'failed' ? $error : null,
        ]);
    }

    private function pruneDestination(string $destination, int $keep, float $deadline): void
    {
        $sets = array_values(array_filter(
            $this->catalog->sets($destination, $this->catalog->files($destination, $deadline)),
            static fn (array $set): bool => $set['verified'],
        ));

        foreach (array_slice($sets, $keep) as $set) {
            $setId = $set['set_id'];
            if ($setId !== '' && basename($setId) === $setId) {
                (new Filesystem)->deleteDirectory($destination.DIRECTORY_SEPARATOR.$setId);
            }
        }
    }

    private function ensureBeforeDeadline(float $deadline): void
    {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Off-site copy exceeded its operation deadline.');
        }
    }
}
