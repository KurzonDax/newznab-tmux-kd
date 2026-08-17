<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\DatabaseBackup;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BackupCatalog
{
    /**
     * @return list<array{manifest_path: string, dump_path: string, manifest: array<string, mixed>, verified: bool}>
     */
    public function files(string $location, ?float $deadline = null): array
    {
        $files = [];

        foreach (glob(rtrim($location, DIRECTORY_SEPARATOR).'/*/*.manifest.json') ?: [] as $manifestPath) {
            try {
                $this->ensureBeforeDeadline($deadline);
                $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($manifest)) {
                    continue;
                }

                $directorySetId = basename(dirname($manifestPath));
                if (($manifest['set_id'] ?? null) !== $directorySetId) {
                    Log::warning('Ignoring database backup manifest whose set ID does not match its directory.', [
                        'path' => $manifestPath,
                        'set_id' => $manifest['set_id'] ?? null,
                    ]);

                    continue;
                }

                if (! $this->manifestIsValid($manifest)) {
                    Log::warning('Ignoring database backup manifest with invalid or missing fields.', [
                        'path' => $manifestPath,
                    ]);

                    continue;
                }

                $dumpPath = substr($manifestPath, 0, -strlen('.manifest.json'));
                $expectedChecksum = (string) ($manifest['sha256'] ?? '');
                $verified = is_file($dumpPath)
                    && filesize($dumpPath) === $manifest['bytes']
                    && $expectedChecksum !== ''
                    && hash_equals($expectedChecksum, $this->checksum($dumpPath, $deadline));

                $files[] = [
                    'manifest_path' => $manifestPath,
                    'dump_path' => $dumpPath,
                    'manifest' => $manifest,
                    'verified' => $verified,
                ];
            } catch (\Throwable $e) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    throw $e;
                }

                Log::warning('Ignoring unreadable database backup manifest.', [
                    'path' => $manifestPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        usort($files, static fn (array $left, array $right): int => strcmp(
            (string) ($right['manifest']['started_at'] ?? ''),
            (string) ($left['manifest']['started_at'] ?? ''),
        ));

        return $files;
    }

    /**
     * @param  list<array{manifest_path: string, dump_path: string, manifest: array<string, mixed>, verified: bool}>|null  $files
     * @return list<array{set_id: string, files: list<array{manifest_path: string, dump_path: string, manifest: array<string, mixed>, verified: bool}>, bytes: int, has_full: bool, has_verified_full: bool, verified: bool, newest_at: string}>
     */
    public function sets(string $location, ?array $files = null): array
    {
        $sets = [];

        foreach ($files ?? $this->files($location) as $file) {
            $setId = (string) ($file['manifest']['set_id'] ?? basename(dirname($file['manifest_path'])));
            $sets[$setId] ??= [
                'set_id' => $setId,
                'files' => [],
                'bytes' => 0,
                'has_full' => false,
                'has_verified_full' => false,
                'verified' => true,
                'newest_at' => '',
            ];
            $sets[$setId]['files'][] = $file;
            $sets[$setId]['bytes'] += (int) ($file['manifest']['bytes'] ?? 0);
            $sets[$setId]['has_full'] = $sets[$setId]['has_full'] || ($file['manifest']['kind'] ?? null) === 'full';
            $sets[$setId]['has_verified_full'] = $sets[$setId]['has_verified_full']
                || (($file['manifest']['kind'] ?? null) === 'full' && $file['verified']);
            $sets[$setId]['verified'] = $sets[$setId]['verified'] && $file['verified'];
            $sets[$setId]['newest_at'] = max(
                $sets[$setId]['newest_at'],
                (string) ($file['manifest']['finished_at'] ?? ''),
            );
        }

        $result = array_values($sets);
        usort($result, static fn (array $left, array $right): int => strcmp($right['newest_at'], $left['newest_at']));

        return $result;
    }

    /**
     * @param  list<array{manifest_path: string, dump_path: string, manifest: array<string, mixed>, verified: bool}>|null  $files
     * @return list<array{set_id: string, files: list<array{manifest_path: string, dump_path: string, manifest: array<string, mixed>, verified: bool}>, bytes: int, has_full: bool, has_verified_full: bool, verified: bool, newest_at: string}>
     */
    public function reconcile(string $location, ?array $files = null): array
    {
        $files ??= $this->files($location);
        $paths = [];

        foreach ($files as $file) {
            $this->recordSuccess($file['manifest_path'], $file['manifest']);
            $paths[] = $file['manifest_path'];
        }

        $stale = DatabaseBackup::query()->where('status', 'successful');
        if ($paths === []) {
            $stale->delete();
        } else {
            $stale->whereNotIn('path', $paths)->delete();
        }

        return $this->sets($location, $files);
    }

    public function purgeOldSets(string $location, int $keepFulls, ?float $deadline = null): void
    {
        $keepFulls = max(1, $keepFulls);
        $files = $this->files($location, $deadline);
        $fullSets = array_values(array_filter(
            $this->sets($location, $files),
            static fn (array $set): bool => $set['has_verified_full'],
        ));
        $deletedSetIds = [];

        foreach (array_slice($fullSets, $keepFulls) as $set) {
            $setId = $set['set_id'];
            if ($setId === '' || basename($setId) !== $setId) {
                Log::warning('Refusing to purge database Backup set with an unsafe identifier.', ['set_id' => $setId]);

                continue;
            }

            if ((new Filesystem)->deleteDirectory(rtrim($location, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$setId)) {
                $deletedSetIds[] = $setId;
            }
        }

        $remainingFiles = array_values(array_filter(
            $files,
            static fn (array $file): bool => ! in_array($file['manifest']['set_id'], $deletedSetIds, true),
        ));
        $this->reconcile($location, $remainingFiles);
    }

    public function latestFinishedAt(string $location, string $kind): ?Carbon
    {
        foreach ($this->files($location) as $file) {
            if (($file['manifest']['kind'] ?? null) === $kind && $file['verified']) {
                return Carbon::parse((string) $file['manifest']['finished_at']);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestManifest(string $location, string $kind, ?float $deadline = null): ?array
    {
        foreach ($this->files($location, $deadline) as $file) {
            if (($file['manifest']['kind'] ?? null) === $kind && $file['verified']) {
                return $file['manifest'];
            }
        }

        return null;
    }

    public function checksum(string $path, ?float $deadline = null): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to read backup file for checksum: {$path}");
        }

        $context = hash_init('sha256');

        try {
            while (! feof($handle)) {
                $this->ensureBeforeDeadline($deadline);
                $chunk = fread($handle, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException("Unable to read backup file for checksum: {$path}");
                }

                if ($chunk !== '') {
                    hash_update($context, $chunk);
                }
            }

            $this->ensureBeforeDeadline($deadline);

            return hash_final($context);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function recordSuccess(string $manifestPath, array $manifest): DatabaseBackup
    {
        return DatabaseBackup::query()->updateOrCreate(
            ['path' => $manifestPath],
            [
                'kind' => $manifest['kind'],
                'set_id' => $manifest['set_id'],
                'bytes' => $manifest['bytes'],
                'sha256' => $manifest['sha256'],
                'started_at' => Carbon::parse($manifest['started_at']),
                'finished_at' => Carbon::parse($manifest['finished_at']),
                'status' => 'successful',
                'error' => null,
            ],
        );
    }

    public function recordFailure(string $kind, string $setId, Carbon $startedAt, string $error): DatabaseBackup
    {
        return DatabaseBackup::query()->create([
            'kind' => $kind,
            'set_id' => $setId,
            'path' => null,
            'started_at' => $startedAt,
            'finished_at' => Carbon::now(),
            'status' => 'failed',
            'error' => $error,
        ]);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function manifestIsValid(array $manifest): bool
    {
        if (! in_array($manifest['kind'] ?? null, ['full', 'daily'], true)
            || ! is_string($manifest['set_id'] ?? null)
            || ! is_string($manifest['started_at'] ?? null)
            || ! is_string($manifest['finished_at'] ?? null)
            || ! is_int($manifest['bytes'] ?? null)
            || $manifest['bytes'] < 1
            || ! is_string($manifest['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $manifest['sha256']) !== 1
            || ! is_string($manifest['app_version'] ?? null)
            || ! is_string($manifest['db_server_version'] ?? null)
            || ! $this->isStringList($manifest['tables'] ?? null)
            || ! $this->isStringList($manifest['tiers_included'] ?? null)) {
            return false;
        }

        try {
            Carbon::parse($manifest['started_at']);
            Carbon::parse($manifest['finished_at']);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function isStringList(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && array_all($value, static fn (mixed $item): bool => is_string($item));
    }

    private function ensureBeforeDeadline(?float $deadline): void
    {
        if ($deadline !== null && microtime(true) >= $deadline) {
            throw new RuntimeException('Database backup checksum exceeded its operation deadline.');
        }
    }
}
