<?php

declare(strict_types=1);

namespace App\Services\ProviderGateway;

use Closure;
use Psr\Http\Message\ResponseInterface;

/** One call through the gateway: the provider's policy and what the caller supplied. */
final readonly class GatewayCall
{
    /**
     * @param  (Closure(): (bool|void))|null  $budget
     * @param  (Closure(ResponseInterface): ?ProviderOutcome)|null  $classify
     * @param  bool  $laravel  Whether a short-circuit is raised for Laravel's client rather than a Guzzle client.
     */
    public function __construct(
        public string $provider,
        public ProviderPolicy $policy,
        public ?object $owner,
        public ?Closure $budget,
        public ?Closure $classify,
        public bool $laravel,
    ) {}
}
