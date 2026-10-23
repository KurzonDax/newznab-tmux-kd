<?php

declare(strict_types=1);

namespace App\Services\ProviderGateway;

/**
 * What one provider call came to. Only Found and NotFound are answers worth caching:
 * Rejected is a request the provider refused (a 4xx other than 404), and Unavailable
 * means no answer was had (a 5xx, 429, timeout or connection error, or the gateway
 * declining to send).
 */
enum ProviderOutcome: string
{
    case Found = 'found';
    case NotFound = 'not_found';
    case Rejected = 'rejected';
    case Unavailable = 'unavailable';

    /** Whether the provider answered: the breaker treats every answer as a success. */
    public function answered(): bool
    {
        return $this !== self::Unavailable;
    }
}
