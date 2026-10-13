<?php

declare(strict_types=1);

namespace App\Services\DecisionModel\Questions;

/**
 * Pick one option. The answer carries the chosen key, a probability for every
 * option and a confidence.
 */
final readonly class ChoiceQuestion implements DecisionQuestion
{
    /**
     * @param  array<string, string>  $criteria  Option key => what that option means.
     */
    public function __construct(
        public string $instructions,
        public array $criteria,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'choice',
            'instructions' => $this->instructions,
            'criteria' => $this->criteria,
        ];
    }
}
