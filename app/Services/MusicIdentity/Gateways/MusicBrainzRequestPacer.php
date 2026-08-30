<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Gateways;

use App\Services\MusicIdentity\Exceptions\MusicBrainzGatewayException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class MusicBrainzRequestPacer
{
    public function pace(string $endpoint): void
    {
        if (! $this->isPublicEndpoint($endpoint)) {
            return;
        }

        $minimumMicroseconds = max(
            1_000,
            (int) config('music-identity.musicbrainz.public_min_interval_milliseconds', 1_000),
        ) * 1_000;

        try {
            Cache::lock('musicbrainz:public-request-pacing', 5)->block(5, function () use ($minimumMicroseconds): void {
                $lastRequestAt = (float) Cache::get('musicbrainz:public-last-request-at', 0.0);
                $remaining = $minimumMicroseconds - (int) ((microtime(true) - $lastRequestAt) * 1_000_000);
                if ($remaining > 0) {
                    usleep($remaining);
                }

                Cache::put('musicbrainz:public-last-request-at', microtime(true), 60);
            });
        } catch (LockTimeoutException $exception) {
            throw new MusicBrainzGatewayException('Timed out waiting for the MusicBrainz public rate limiter.', previous: $exception);
        }
    }

    public function isPublicEndpoint(string $endpoint): bool
    {
        return strtolower((string) parse_url($endpoint, PHP_URL_HOST)) === 'musicbrainz.org';
    }
}
