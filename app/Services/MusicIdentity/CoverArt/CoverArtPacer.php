<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\CoverArt;

use App\Services\ProviderGateway\ProviderGateway;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * One Cover Art Archive lookup at a time across every worker process, each starting at least
 * `music-identity.cover_art.min_interval_milliseconds` after the previous one ended. The shared
 * cache lock is held for the whole lookup, its redirect hops included. A delegate to the provider
 * gateway's `serial` pacing of the `coverart` entry.
 */
final class CoverArtPacer
{
    public const string LOCK = 'cover-art-archive:lookup';

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
        return app(ProviderGateway::class)->serial('coverart', $lookup);
    }
}
