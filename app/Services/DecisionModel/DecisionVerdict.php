<?php

declare(strict_types=1);

namespace App\Services\DecisionModel;

/**
 * What a use case made of the model's answers.
 */
interface DecisionVerdict
{
    /**
     * The verdict as the decision log stores it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
