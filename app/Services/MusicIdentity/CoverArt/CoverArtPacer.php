<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\CoverArt;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * One Cover Art Archive lookup at a time across every worker process, each starting at least
 * `music-identity.cover_art.min_interval_milliseconds` after the previous one ended. The shared
 * cache lock is held for the whole lookup, its redirect hops included.
 */
final class CoverArtPacer
{
    public const string LOCK = 'cover-art-archive:lookup';

    private const string LAST_LOOKUP_AT = 'cover-art-archive:last-lookup-at';

    /**
     * @template T
     *
     * @param  Closure(): T  $lookup
     * @return T
     *
     * @throws LockTimeoutException when another lookup holds the lock past `lock_wait_seconds`
     */
    public function run(Closure $lookup): mixed
    {
        $intervalMilliseconds = max(0, (int) config('music-identity.cover_art.min_interval_milliseconds', 1_000));
        $lockSeconds = (int) ceil((float) config('music-identity.cover_art.timeout_seconds', 15)
            + (float) config('music-identity.cover_art.connect_timeout_seconds', 5)
            + $intervalMilliseconds / 1_000) + 5;

        return Cache::lock(self::LOCK, $lockSeconds)->block(
            max(0, (int) config('music-identity.cover_art.lock_wait_seconds', 10)),
            function () use ($lookup, $intervalMilliseconds): mixed {
                $remaining = (float) Cache::get(self::LAST_LOOKUP_AT, 0.0) + $intervalMilliseconds - now()->getPreciseTimestamp(3);
                if ($remaining > 0) {
                    Sleep::usleep((int) ceil($remaining * 1_000));
                }

                try {
                    return $lookup();
                } finally {
                    Cache::put(self::LAST_LOOKUP_AT, now()->getPreciseTimestamp(3), 3_600);
                }
            },
        );
    }
}
