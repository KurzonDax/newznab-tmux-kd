<?php

declare(strict_types=1);

namespace App\Services\ProviderGateway;

use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\RequestInterface;

/** The short-circuit a Guzzle client built by {@see ProviderGateway::guzzle()} raises. */
final class ShortCircuitConnectException extends ConnectException implements ShortCircuit
{
    public function __construct(
        private readonly string $provider,
        private readonly ShortCircuitReason $reason,
        RequestInterface $request,
    ) {
        parent::__construct(sprintf('%s request not sent: %s.', $provider, $reason->value), $request);
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
