<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\StatusProbes\DiskProbe;
use Closure;

/**
 * Disk guard for full-resolution Clips: when the volume holding the covers
 * video storage is below 10% free, no Clip is produced and the pipeline falls
 * back to the small transcode. Measures the same way the status-probe disk
 * machinery does ({@see DiskProbe}).
 */
class ClipDiskGuard
{
    private const float MINIMUM_FREE_FRACTION = 0.10;

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
     * Whether the volume holding $directory has room for a Clip. Unreadable
     * disk metrics refuse the Clip: producing a large artifact is the wrong
     * response to not knowing how full the disk is.
     */
    public function allows(string $directory): bool
    {
        $free = ($this->freeSpace)($directory);
        $total = ($this->totalSpace)($directory);

        if ($free === false || $total === false || $total <= 0) {
            return false;
        }

        return ($free / $total) >= self::MINIMUM_FREE_FRACTION;
    }
}
