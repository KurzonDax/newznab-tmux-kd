<?php

declare(strict_types=1);

namespace App\Services\ProviderGateway;

use Throwable;

/**
 * A provider call's outcome together with the value it produced.
 *
 * @template-covariant TValue
 */
final readonly class ProviderResult
{
    /** @param TValue $value */
    public function __construct(
        public ProviderOutcome $outcome,
        public mixed $value = null,
        public ?Throwable $error = null,
    ) {}

    /**
     * @template T
     *
     * @param  T  $value
     * @return self<T>
     */
    public static function found(mixed $value): self
    {
        return new self(ProviderOutcome::Found, $value);
    }

    /** @return self<null> */
    public static function notFound(): self
    {
        return new self(ProviderOutcome::NotFound);
    }

    /** @return self<null> */
    public static function rejected(?Throwable $error = null): self
    {
        return new self(ProviderOutcome::Rejected, error: $error);
    }

    /** @return self<null> */
    public static function unavailable(?Throwable $error = null): self
    {
        return new self(ProviderOutcome::Unavailable, error: $error);
    }

    public function isFound(): bool
    {
        return $this->outcome === ProviderOutcome::Found;
    }

    public function isNotFound(): bool
    {
        return $this->outcome === ProviderOutcome::NotFound;
    }

    public function isUnavailable(): bool
    {
        return $this->outcome === ProviderOutcome::Unavailable;
    }
}
