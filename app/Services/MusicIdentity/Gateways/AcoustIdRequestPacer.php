<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Gateways;

use App\Services\MusicIdentity\Exceptions\AcousticFingerprintLookupException;
use App\Services\ProviderGateway\ProviderGateway;
use App\Services\ProviderGateway\ShortCircuitReason;

/**
 * Spaces AcoustID requests across every worker process sharing the cache store, so that no second
 * holds more than `music-identity.acoustid.requests_per_second` dispatches: a delegate to the
 * provider gateway's shared `acoustid` pacer, which every AcoustID request goes through.
 */
final class AcoustIdRequestPacer
{
    /** @throws AcousticFingerprintLookupException when the next slot cannot be had in time */
    public function pace(): void
    {
        if (app(ProviderGateway::class)->pace('acoustid') instanceof ShortCircuitReason) {
            throw new AcousticFingerprintLookupException('Timed out waiting for the shared AcoustID rate limiter.', retryable: true);
        }
    }

    /** Applies a service's Retry-After to every worker: no slot is handed out before it ends. */
    public function holdOff(int $milliseconds): void
    {
        app(ProviderGateway::class)->hold('acoustid', $milliseconds);
    }
}
