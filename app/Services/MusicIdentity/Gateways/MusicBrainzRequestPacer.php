<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Gateways;

use App\Services\MusicIdentity\Exceptions\MusicBrainzGatewayException;
use App\Services\ProviderGateway\ProviderGateway;
use App\Services\ProviderGateway\ShortCircuitReason;

/**
 * Spaces a request to the public musicbrainz.org in step with every lookup: a delegate to the
 * provider gateway's shared `musicbrainz` pacer for requests made outside it (the status probe).
 */
final class MusicBrainzRequestPacer
{
    public function __construct(private readonly ProviderGateway $gateway) {}

    public function pace(string $endpoint): void
    {
        if (! $this->isPublicEndpoint($endpoint)) {
            return;
        }

        if ($this->gateway->pace('musicbrainz', 'musicbrainz.org') instanceof ShortCircuitReason) {
            throw new MusicBrainzGatewayException('Timed out waiting for the MusicBrainz public rate limiter.');
        }
    }

    public function isPublicEndpoint(string $endpoint): bool
    {
        return strtolower((string) parse_url($endpoint, PHP_URL_HOST)) === 'musicbrainz.org';
    }
}
