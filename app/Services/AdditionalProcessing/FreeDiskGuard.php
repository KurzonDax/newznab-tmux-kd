<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\StatusProbes\DiskProbe;
use Closure;

/**
 * The Free-disk guard (see CONTEXT.md and ADR 0013): one threshold shared by
 * every disk-hungry producer writing to the covers volume. Each producer
 * decides its own response -- Clips degrade to the small transcode, release
 * imagery is skipped entirely and recorded on the Imagery disk skip ledger.
 *
 * Measures the same way the status-probe disk machinery does ({@see DiskProbe}).
 */
class FreeDiskGuard
{
    private const float DEFAULT_MINIMUM_FREE_FRACTION = 0.10;

    /**
     * @var Closure(string): (float|false)
     */
    private readonly Closure $freeSpace;

    /**
     * @var Closure(string): (float|false)
     */
    private readonly Closure $totalSpace;

    /**
     * @param  (callable(string): (float|false))|null  $freeSpace
     * @param  (callable(string): (float|false))|null  $totalSpace
     */
    public function __construct(?callable $freeSpace = null, ?callable $totalSpace = null)
    {
        $this->freeSpace = Closure::fromCallable($freeSpace ?? static fn (string $path): float|false => @disk_free_space($path));
        $this->totalSpace = Closure::fromCallable($totalSpace ?? static fn (string $path): float|false => @disk_total_space($path));
    }

    /**
     * Whether the covers volume has room to grow. Unreadable disk metrics
     * refuse: producing large artifacts is the wrong response to not knowing
     * how full the disk is.
     */
    public function allows(): bool
    {
        $directory = $this->measurableCoversPath();
        if ($directory === null) {
            return false;
        }

        $free = ($this->freeSpace)($directory);
        $total = ($this->totalSpace)($directory);

        if ($free === false || $total === false || $total <= 0) {
            return false;
        }

        return ($free / $total) >= $this->minimumFreeFraction();
    }

    /**
     * The nearest existing ancestor of the covers root. Free space is a
     * property of the volume, not the directory, and the covers subdirectories
     * are created lazily by their producers -- measuring a path that does not
     * exist yet would refuse every artifact on a fresh install.
     */
    private function measurableCoversPath(): ?string
    {
        $configured = config('nntmux_settings.covers_path', storage_path('covers'));
        $path = is_string($configured) && $configured !== ''
            ? rtrim($configured, '/\\')
            : storage_path('covers');

        while ($path !== '') {
            if (is_dir($path)) {
                return $path;
            }

            $parent = dirname($path);
            if ($parent === $path) {
                return null;
            }

            $path = $parent;
        }

        return null;
    }

    /**
     * The configured threshold, clamped to a fraction. A value outside (0, 1)
     * would either disable the guard or refuse every artifact forever, so an
     * out-of-range setting falls back to the default rather than being obeyed.
     */
    private function minimumFreeFraction(): float
    {
        $configured = (float) config('nntmux_settings.covers_minimum_free_fraction', self::DEFAULT_MINIMUM_FREE_FRACTION);

        return $configured > 0.0 && $configured < 1.0 ? $configured : self::DEFAULT_MINIMUM_FREE_FRACTION;
    }
}
