<?php

declare(strict_types=1);

namespace App\Services\ProviderGateway;

/** A breaker's leave to send one attempt; a trial decides whether a shared breaker closes. */
final readonly class BreakerPermit
{
    public function __construct(public bool $trial) {}
}
