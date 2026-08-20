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
 *
 * Ownership, not the shape of a filename, decides what may be rotated: a file
 * is Monolog's only when it is `<stem>-YYYY-MM-DD.log` for the stem of a
 * `daily`-driver channel. Anything else stays eligible however it is named, so
 * a file this service already rotated can be rotated again rather than becoming
 * permanently exempt, and rotation never claims a name the daily driver is
 * going to write for itself.
 */
class LogRetentionService
{
    /**
     * Monolog's `-YYYY-MM-DD` rotation suffix, plus the numeric tail this
     * service adds when a rotation target is already spoken for.
     */
    private const string DATED_SUFFIX_PATTERN = '/-\d{4}-\d{2}-\d{2}(?:-\d+)?$/';

    /**
     * Monolog's dated filename exactly as its daily driver writes it.
     */
    private const string MONOLOG_DATED_NAME_PATTERN = '/^(.+)-\d{4}-\d{2}-\d{2}\.log$/';

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
     * The prune window, in days; 0 disables pruning.
     *
     * Deliberately the daily channels' own retention rather than an independent
     * value: the sweep below deletes Monolog's dated files too, so a window of
     * its own would fight the daily driver. That includes the disabled case —
     * Monolog reads `days` of 0 as keep-forever, so pruning has to stop too.
     */
    public function retentionDays(): int
    {
        return max(0, (int) config('logging.channels.daily.days'));
    }

    /**
     * The size a file must reach to be rotated; 0 disables rotation.
     */
    public function rotationThresholdBytes(): int
    {
        return max(0, (int) config('nntmux.log_retention.rotate_size_mb')) * 1024 * 1024;
    }

    /**
     * Rotate oversized unmanaged logs, then delete everything past the window.
     *
     * @return array{rotated: list<array{from: string, to: string}>, pruned: list<string>}
     */
    public function sweep(bool $dryRun = false): array
    {
        if (! File::isDirectory($this->logsDirectory)) {
            return ['rotated' => [], 'pruned' => []];
        }

        $now = CarbonImmutable::now();

        return [
            'rotated' => $this->rotateOversizedLogs($now, $dryRun),
            'pruned' => $this->pruneExpiredLogs($now, $dryRun),
        ];
    }

    /**
     * @return list<array{from: string, to: string}>
     */
    private function rotateOversizedLogs(CarbonImmutable $now, bool $dryRun): array
    {
        $threshold = $this->rotationThresholdBytes();

        if ($threshold === 0) {
            return [];
        }

        $monologStems = $this->monologDailyStems();
        $rotated = [];

        // A dry run renames nothing, so targets it reports have to be reserved
        // here or two files sharing a stem would both report the same name.
        $claimed = [];

        foreach ($this->logFiles() as $path) {
            $name = basename($path);

            if ($this->isWrittenByMonolog($name, $monologStems)) {
                continue;
            }

            $size = @filesize($path);

            if ($size === false || $size < $threshold) {
                continue;
            }

            $target = $this->rotationTargetFor($name, $monologStems, $claimed, $now);

            if ($target === null) {
                continue;
            }

            // A rename keeps the live inode intact for anything still holding the
            // file open, and the next cron invocation reopens the original path.
            if (! $dryRun && ! @rename($path, $target)) {
                continue;
            }

            $claimed[$target] = true;
            $rotated[] = ['from' => $name, 'to' => basename($target)];
        }

        return $rotated;
    }

    /**
     * @return list<string>
     */
    private function pruneExpiredLogs(CarbonImmutable $now, bool $dryRun): array
    {
        $days = $this->retentionDays();

        if ($days === 0) {
            return [];
        }

        $cutoff = $now->subDays($days)->getTimestamp();
        $pruned = [];

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

        return $pruned;
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

    /**
     * Filename stems the `daily`-driver channels write dated files under.
     *
     * @return array<string, true>
     */
    private function monologDailyStems(): array
    {
        $stems = [];

        foreach ((array) config('logging.channels', []) as $channel) {
            if (! is_array($channel) || ($channel['driver'] ?? null) !== 'daily') {
                continue;
            }

            $path = $channel['path'] ?? null;

            if (! is_string($path) || ! str_ends_with($path, '.log')) {
                continue;
            }

            $stems[substr(basename($path), 0, -strlen('.log'))] = true;
        }

        return $stems;
    }

    /**
     * @param  array<string, true>  $monologStems
     */
    private function isWrittenByMonolog(string $name, array $monologStems): bool
    {
        if (preg_match(self::MONOLOG_DATED_NAME_PATTERN, $name, $matches) !== 1) {
            return false;
        }

        return isset($monologStems[$matches[1]]);
    }

    /**
     * The dated name to rotate a file into, or null when every candidate is taken.
     *
     * Monolog's own `name-YYYY-MM-DD.log` convention keeps the log viewer sorting
     * naturally, but a stem the daily driver owns is skipped straight to the
     * numeric tail: the plain dated name belongs to Monolog even on a day it has
     * not written it yet, and appending a 9 GB `single`-driver leftover into the
     * file the daily driver is about to open would be worse than not rotating.
     *
     * @param  array<string, true>  $monologStems
     * @param  array<string, true>  $claimed
     */
    private function rotationTargetFor(string $name, array $monologStems, array $claimed, CarbonImmutable $now): ?string
    {
        $base = substr($name, 0, -strlen('.log'));
        $stem = preg_replace(self::DATED_SUFFIX_PATTERN, '', $base) ?? $base;
        $date = $now->format('Y-m-d');
        $firstAttempt = isset($monologStems[$stem]) ? 1 : 0;

        for ($attempt = $firstAttempt; $attempt < $firstAttempt + self::MAX_ROTATION_ATTEMPTS; $attempt++) {
            $suffix = $attempt === 0 ? '' : '-'.$attempt;
            $candidate = $this->logsDirectory.DIRECTORY_SEPARATOR.$stem.'-'.$date.$suffix.'.log';

            if (! file_exists($candidate) && ! isset($claimed[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }
}
