<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Gateways;

use App\Services\MusicIdentity\Exceptions\AcousticFingerprintLookupException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * Spaces AcoustID requests across every worker process sharing the cache store, so that no second
 * holds more than `music-identity.acoustid.requests_per_second` dispatches. Every attempt, retries
 * included, reserves its slot here immediately before it is sent: under a short shared lock the
 * worker takes the next free slot (at least one interval after the last reserved one, and never
 * before a service-requested hold-off), then sleeps until that slot outside the lock.
 */
final class AcoustIdRequestPacer
{
    public const string LOCK = 'acoustid:request-pacing';

    private const string LAST_SLOT_AT = 'acoustid:last-request-at';

    private const string NOT_BEFORE = 'acoustid:not-before';

    /** @throws AcousticFingerprintLookupException when another worker holds the lock past `lock_wait_seconds` */
    public function pace(): void
    {
        $intervalMilliseconds = (int) ceil(1_000 / max(1, (int) config('music-identity.acoustid.requests_per_second', 3)));

        $slotAt = $this->locked(function () use ($intervalMilliseconds): float {
            $slotAt = max(
                now()->getPreciseTimestamp(3),
                (float) Cache::get(self::LAST_SLOT_AT, 0.0) + $intervalMilliseconds,
                (float) Cache::get(self::NOT_BEFORE, 0.0),
            );
            Cache::put(self::LAST_SLOT_AT, $slotAt, 3_600);

            return $slotAt;
        });

        $remaining = $slotAt - now()->getPreciseTimestamp(3);
        if ($remaining > 0) {
            Sleep::usleep((int) ceil($remaining * 1_000));
        }
    }

    /**
     * Applies a service's Retry-After to every worker: no slot is handed out before it ends.
     *
     * @throws AcousticFingerprintLookupException when another worker holds the lock past `lock_wait_seconds`
     */
    public function holdOff(int $milliseconds): void
    {
        $this->locked(function () use ($milliseconds): void {
            $notBefore = now()->getPreciseTimestamp(3) + max(0, $milliseconds);
            if ($notBefore > (float) Cache::get(self::NOT_BEFORE, 0.0)) {
                Cache::put(self::NOT_BEFORE, $notBefore, 3_600);
            }
        });
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function locked(\Closure $callback): mixed
    {
        try {
            return Cache::lock(self::LOCK, 5)->block(
                max(0, (int) config('music-identity.acoustid.lock_wait_seconds', 10)),
                $callback,
            );
        } catch (LockTimeoutException $exception) {
            throw new AcousticFingerprintLookupException('Timed out waiting for the shared AcoustID rate limiter.', retryable: true, previous: $exception);
        }
    }
}
