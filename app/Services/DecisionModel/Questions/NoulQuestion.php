<?php

declare(strict_types=1);

namespace App\Services\DecisionModel\Questions;

/**
 * A yes-or-no question answered as a probability of yes.
 */
final readonly class NoulQuestion implements DecisionQuestion
{
    /**
     * @param  array{true?: string, false?: string}|null  $criteria  What a yes and a no mean, when the instructions alone do not say.
     */
    public function __construct(
        public string $instructions,
        public ?array $criteria = null,
    ) {}

    public function toArray(): array
    {
        $question = ['type' => 'noul', 'instructions' => $this->instructions];

        if ($this->criteria !== null && $this->criteria !== []) {
            $question['criteria'] = $this->criteria;
        }

        return $question;
    }
}
