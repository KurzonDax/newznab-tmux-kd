<?php

declare(strict_types=1);

namespace App\Services\DecisionModel;

/**
 * Everything one decision produced: what was sent, what came back, and either
 * the use case's verdict or the reason there is none.
 */
final readonly class DecisionRun
{
    /**
     * @param  string|array<string, mixed>  $state
     * @param  list<DecisionResponse>  $responses  One per request that was answered, in order.
     */
    public function __construct(
        public string $useCase,
        public string $criteriaVersion,
        public string|array $state,
        public array $responses,
        public ?DecisionVerdict $verdict,
        public ?string $failureReason = null,
    ) {}

    public function failed(): bool
    {
        return $this->verdict === null;
    }

    /**
     * The answers of every request, in the order the requests were sent.
     *
     * @return list<array<string, array<string, mixed>>>
     */
    public function answers(): array
    {
        return array_map(static fn (DecisionResponse $response): array => $response->toArray(), $this->responses);
    }
}
