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
    private const string FOLDER_NAME_PATTERN = '/^\d{8}-\d{6}$/';

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

                // A dump is hashed once, when it is written; its manifest keeps that hash.
                // Reads never hash it again, so verified means present at its written size.
                $dumpPath = substr($manifestPath, 0, -strlen('.manifest.json'));
                $verified = is_file($dumpPath) && filesize($dumpPath) === $manifest['bytes'];

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
     * Groups the files into backup folders, newest first by the newest manifest's finished_at.
     *
     * A folder written before ADR 0016 holds one Full plus its Dailies and is still one entry.
     *
     * @param  list<array{manifest_path: string, dump_path: string, manifest: array<string, mixed>, verified: bool}>|null  $files
     * @return list<array{set_id: string, files: list<array{manifest_path: string, dump_path: string, manifest: array<string, mixed>, verified: bool}>, bytes: int, verified: bool, newest_at: string}>
     */
    public function sets(string $location, ?array $files = null): array
    {
        $sets = [];
        $newest = [];

        foreach ($files ?? $this->files($location) as $file) {
            $setId = (string) ($file['manifest']['set_id'] ?? basename(dirname($file['manifest_path'])));
            $sets[$setId] ??= [
                'set_id' => $setId,
                'files' => [],
                'bytes' => 0,
                'verified' => true,
                'newest_at' => '',
            ];
            $sets[$setId]['files'][] = $file;
            $sets[$setId]['bytes'] += (int) ($file['manifest']['bytes'] ?? 0);
            $sets[$setId]['verified'] = $sets[$setId]['verified'] && $file['verified'];

            $finishedAt = (string) ($file['manifest']['finished_at'] ?? '');
            $timestamp = Carbon::parse($finishedAt)->getTimestamp();
            if (! isset($newest[$setId]) || $timestamp > $newest[$setId]) {
                $newest[$setId] = $timestamp;
                $sets[$setId]['newest_at'] = $finishedAt;
            }
        }

        $result = array_values($sets);
        usort($result, static fn (array $left, array $right): int => [$newest[$right['set_id']], $right['set_id']]
            <=> [$newest[$left['set_id']], $left['set_id']]);

        return $result;
    }

    /**
     * @param  list<array{manifest_path: string, dump_path: string, manifest: array<string, mixed>, verified: bool}>|null  $files
     * @return list<array{set_id: string, files: list<array{manifest_path: string, dump_path: string, manifest: array<string, mixed>, verified: bool}>, bytes: int, verified: bool, newest_at: string}>
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

    /**
     * Keeps the newest $keep folders whose files all verify and removes every folder older than the oldest of them.
     *
     * Unverified folders newer than that stay, and nothing is removed while fewer than $keep verified folders
     * exist. A folder with no readable manifest is ordered by the start time in its YYYYMMDD-HHMMSS name;
     * one whose name has another shape is never touched.
     *
     * @return list<array{manifest_path: string, dump_path: string, manifest: array<string, mixed>, verified: bool}> the files left
     */
    public function applyRetention(string $location, int $keep, ?float $deadline = null): array
    {
        $files = $this->files($location, $deadline);
        $removed = [];

        foreach ($this->foldersPastRetention($location, max(1, $keep), $files) as $folder) {
            if ((new Filesystem)->deleteDirectory(rtrim($location, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$folder)) {
                $removed[] = $folder;
            }
        }

        return array_values(array_filter(
            $files,
            static fn (array $file): bool => ! in_array($file['manifest']['set_id'], $removed, true),
        ));
    }

    /**
     * The finish time of the most recent backup attempt: a verified backup in the Backup location or a recorded failed run.
     */
    public function latestAttemptFinishedAt(string $location): ?Carbon
    {
        $latest = null;
        foreach ($this->files($location) as $file) {
            if ($file['verified']) {
                $latest = Carbon::parse((string) $file['manifest']['finished_at']);

                break;
            }
        }

        $failedAt = DatabaseBackup::query()->where('status', 'failed')->max('finished_at');
        if ($failedAt !== null) {
            $failed = Carbon::parse((string) $failedAt);
            if ($latest === null || $failed->greaterThan($latest)) {
                $latest = $failed;
            }
        }

        return $latest;
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

    public function recordFailure(string $setId, Carbon $startedAt, string $error): DatabaseBackup
    {
        return DatabaseBackup::query()->create([
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
        // Manifests written before ADR 0016 also name their kind, full or daily; newer ones have none.
        if ((array_key_exists('kind', $manifest) && ! in_array($manifest['kind'], ['full', 'daily'], true))
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

    /**
     * @param  list<array{manifest_path: string, dump_path: string, manifest: array<string, mixed>, verified: bool}>  $files
     * @return list<string>
     */
    private function foldersPastRetention(string $location, int $keep, array $files): array
    {
        $folders = [];
        foreach ($this->sets($location, $files) as $set) {
            $folders[$set['set_id']] = [Carbon::parse($set['newest_at'])->getTimestamp(), $set['verified']];
        }

        foreach (glob(rtrim($location, DIRECTORY_SEPARATOR).'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $name = basename($directory);
            if (isset($folders[$name]) || preg_match(self::FOLDER_NAME_PATTERN, $name) !== 1) {
                continue;
            }

            $startedAt = Carbon::createFromFormat('Ymd-His', $name, (string) config('app.timezone'));
            if ($startedAt instanceof Carbon) {
                $folders[$name] = [$startedAt->getTimestamp(), false];
            }
        }

        uksort($folders, static fn (int|string $left, int|string $right): int => [$folders[$right][0], (string) $right]
            <=> [$folders[$left][0], (string) $left]);

        $kept = 0;
        $past = [];
        foreach ($folders as $name => [, $verified]) {
            $name = (string) $name;
            if ($kept >= $keep) {
                if ($name === '' || basename($name) !== $name) {
                    Log::warning('Refusing to remove a database backup folder with an unsafe name.', ['set_id' => $name]);

                    continue;
                }

                $past[] = $name;
            } elseif ($verified) {
                $kept++;
            }
        }

        return $past;
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
