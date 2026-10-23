<?php

declare(strict_types=1);

namespace App\Services\ProviderGateway;

/** The process- or instance-scoped breakers of one owner, by provider. */
final class LocalBreakerStates
{
    /** @var array<string, array{failures: int, open: bool, open_until: float|null}> */
    public array $states = [];
}
