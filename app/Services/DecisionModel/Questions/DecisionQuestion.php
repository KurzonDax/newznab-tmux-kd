<?php

declare(strict_types=1);

namespace App\Services\DecisionModel\Questions;

/**
 * One typed question put to the decision model about a state.
 */
interface DecisionQuestion
{
    /**
     * The question as the service's request body carries it.
     *
     * @return array{type: string, instructions: string, criteria?: array<array-key, string|null>}
     */
    public function toArray(): array;
}
