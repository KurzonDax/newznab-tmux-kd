<?php

declare(strict_types=1);

namespace App\Services\DecisionModel\Questions;

/**
 * Place the state on an ordered scale.
 */
final readonly class ScoreQuestion implements DecisionQuestion
{
    /**
     * @param  list<string>  $criteria  The levels of the scale, in order.
     */
    public function __construct(
        public string $instructions,
        public array $criteria,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'score',
            'instructions' => $this->instructions,
            'criteria' => array_values($this->criteria),
        ];
    }
}
