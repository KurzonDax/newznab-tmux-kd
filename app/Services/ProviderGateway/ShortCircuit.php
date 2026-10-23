<?php

declare(strict_types=1);

namespace App\Services\ProviderGateway;

use Throwable;

/**
 * A request the gateway declined to send. It is raised as a connection failure, so every
 * existing transport-error path handles it: {@see ShortCircuitConnectException} from a
 * Guzzle client, {@see ShortCircuitConnectionException} from Laravel's HTTP client.
 */
interface ShortCircuit extends Throwable
{
    public function provider(): string;

    public function reason(): ShortCircuitReason;
}
