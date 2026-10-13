<?php

declare(strict_types=1);

namespace App\Services\DecisionModel;

use InvalidArgumentException;

/**
 * The use cases the decision model can be asked for, by name.
 */
final class DecisionUseCaseRegistry
{
    /** @var array<string, DecisionUseCase> */
    private array $useCases = [];

    /**
     * @param  iterable<DecisionUseCase>  $useCases
     */
    public function __construct(iterable $useCases = [])
    {
        foreach ($useCases as $useCase) {
            $this->register($useCase);
        }
    }

    public function register(DecisionUseCase $useCase): void
    {
        $name = $useCase->name();

        if (isset($this->useCases[$name])) {
            throw new InvalidArgumentException("A decision use case named [{$name}] is already registered.");
        }

        $this->useCases[$name] = $useCase;
    }

    public function get(string $name): DecisionUseCase
    {
        return $this->useCases[$name]
            ?? throw new InvalidArgumentException("No decision use case named [{$name}] is registered.");
    }
}
