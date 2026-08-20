<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * Gives every file in storage/logs a retention owner.
 *
 * Monolog's daily driver rotates and prunes the dated files it writes itself,
 * but nothing owns the rest of the directory: cron `>>` redirects such as
 * schedule.log, ad-hoc redirects such as horizon.log, and the plain files left
 * behind by `single`-driver channels. Those are rotated here once they outgrow
 * the configured size — an mtime prune alone can never bound a file that is
 * still being appended to — and then everything, Monolog's dated files
 * included, is pruned once it falls outside the retention window.
 */
class LogRetentionService
{
    /**
     * Matches Monolog's `name-YYYY-MM-DD.log` rotation suffix, plus the numeric
     * tail this service adds when a rotation target is already taken.
     */
    private const string ROTATED_NAME_PATTERN = '/-\d{4}-\d{2}-\d{2}(?:-\d+)?\.log$/';

    /**
     * Distinct rotation targets tried for one file before giving up on it.
     */
    private const int MAX_ROTATION_ATTEMPTS = 100;

    private string $logsDirectory;

    public function __construct(?string $logsDirectory = null)
    {
        $this->logsDirectory = rtrim($logsDirectory ?? storage_path('logs'), DIRECTORY_SEPARATOR);
    }

    /**
     * The prune window, in days.
     *
     * Deliberately the daily channels' own retention rather than an independent
     * value: the sweep below deletes Monolog's dated files too, so a shorter
     * window here would throw away files the daily driver intends to keep.
     */
    public function retentionDays(): int
    {
        return max(1, (int) config('logging.channels.daily.days', 7));
    }

    public function rotationThresholdBytes(): int
    {
        return max(1, (int) config('nntmux.log_retention.rotate_size_mb', 256)) * 1024 * 1024;
    }

    /**
     * Rotate oversized unmanaged logs, then delete everything past the window.
     *
     * @return array{rotated: list<array{from: string, to: string}>, pruned: list<string>}
     */
    public function sweep(bool $dryRun = false): array
    {
        $rotated = [];
        $pruned = [];

        if (! File::isDirectory($this->logsDirectory)) {
            return ['rotated' => $rotated, 'pruned' => $pruned];
        }

        $threshold = $this->rotationThresholdBytes();
        $now = CarbonImmutable::now();

        foreach ($this->logFiles() as $path) {
            if ($this->isRotatedName(basename($path))) {
                continue;
            }

            $size = @filesize($path);

            if ($size === false || $size < $threshold) {
                continue;
            }

            $target = $this->rotationTargetFor($path, $now);

            if ($target === null) {
                continue;
            }

            // A rename keeps the live inode intact for anything still holding the
            // file open, and the next cron invocation reopens the original path.
            if (! $dryRun && ! @rename($path, $target)) {
                continue;
            }

            $rotated[] = ['from' => basename($path), 'to' => basename($target)];
        }

        $cutoff = $now->subDays($this->retentionDays())->getTimestamp();

        foreach ($this->logFiles() as $path) {
            $modifiedAt = @filemtime($path);

            if ($modifiedAt === false || $modifiedAt >= $cutoff) {
                continue;
            }

            if (! $dryRun && ! @unlink($path)) {
                continue;
            }

            $pruned[] = basename($path);
        }

        return ['rotated' => $rotated, 'pruned' => $pruned];
    }

    /**
     * The `*.log` files directly in the log directory. Never recursive: whatever
     * an operator parked in a subdirectory is not this service's to delete.
     *
     * @return list<string>
     */
    private function logFiles(): array
    {
        $paths = [];

        foreach (File::files($this->logsDirectory) as $file) {
            if (strtolower($file->getExtension()) !== 'log') {
                continue;
            }

            $paths[] = $file->getPathname();
        }

        sort($paths);

        return $paths;
    }

    private function isRotatedName(string $name): bool
    {
        return preg_match(self::ROTATED_NAME_PATTERN, $name) === 1;
    }

    /**
     * The dated name to rotate a file into, or null when every candidate is taken.
     *
     * Monolog's own `name-YYYY-MM-DD.log` convention keeps the log viewer sorting
     * naturally, but it also means a rotation can collide with a file the daily
     * driver owns — so a taken name is never overwritten.
     */
    private function rotationTargetFor(string $path, CarbonImmutable $now): ?string
    {
        $base = substr(basename($path), 0, -strlen('.log'));
        $date = $now->format('Y-m-d');

        for ($attempt = 0; $attempt < self::MAX_ROTATION_ATTEMPTS; $attempt++) {
            $suffix = $attempt === 0 ? '' : '-'.$attempt;
            $candidate = $this->logsDirectory.DIRECTORY_SEPARATOR.$base.'-'.$date.$suffix.'.log';

            if (! file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
