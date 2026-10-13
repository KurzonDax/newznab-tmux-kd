<?php

declare(strict_types=1);

namespace App\Services\DecisionModel;

/**
 * The answers to one request, keyed by the ids the questions were asked under.
 */
final readonly class DecisionResponse
{
    /**
     * @param  array<string, DecisionAnswer>  $answers
     */
    public function __construct(public array $answers) {}

    public function answer(string $question): ?DecisionAnswer
    {
        return $this->answers[$question] ?? null;
    }

    /**
     * The answer to a question, or null when it was not asked or it failed.
     */
    public function usableAnswer(string $question): ?DecisionAnswer
    {
        $answer = $this->answer($question);

        return $answer === null || $answer->failed() ? null : $answer;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (DecisionAnswer $answer): array => $answer->toArray(), $this->answers);
    }
}
