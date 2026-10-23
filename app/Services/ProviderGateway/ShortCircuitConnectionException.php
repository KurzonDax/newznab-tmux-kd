<?php

declare(strict_types=1);

namespace App\Services\ProviderGateway;

use Illuminate\Http\Client\ConnectionException;

/**
 * The short-circuit a request from {@see ProviderGateway::http()} raises: the connection
 * failure Laravel's client would have thrown, without recording a request that was never sent.
 */
final class ShortCircuitConnectionException extends ConnectionException implements ShortCircuit
{
    public function __construct(
        private readonly string $provider,
        private readonly ShortCircuitReason $reason,
    ) {
        parent::__construct(sprintf('%s request not sent: %s.', $provider, $reason->value));
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function reason(): ShortCircuitReason
    {
        return $this->reason;
    }
}
