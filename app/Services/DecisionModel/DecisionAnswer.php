<?php

declare(strict_types=1);

namespace App\Services\DecisionModel;

/**
 * The service's answer to one question, with its fields as the API returned
 * them. Which fields are set depends on the question's type: a Choice carries
 * `choice`, `probabilities` and `confidence`, a Noul `noul`, a Score `score`
 * and `legend`. A failed answer carries `error` instead.
 */
final readonly class DecisionAnswer
{
    /**
     * @param  array<string, float>|null  $probabilities
     * @param  array<array-key, mixed>|null  $legend
     * @param  array<string, mixed>  $raw  The answer exactly as received, for the log.
     */
    public function __construct(
        public ?string $choice = null,
        public ?array $probabilities = null,
        public ?float $confidence = null,
        public ?float $noul = null,
        public int|float|null $score = null,
        public ?array $legend = null,
        public ?string $error = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $answer
     */
    public static function fromArray(array $answer): self
    {
        if (array_key_exists('error', $answer)) {
            return new self(
                error: is_scalar($answer['error']) && (string) $answer['error'] !== '' ? (string) $answer['error'] : 'error',
                message: is_scalar($answer['message'] ?? null) ? (string) $answer['message'] : null,
                raw: $answer,
            );
        }

        $probabilities = null;
        if (is_array($answer['probabilities'] ?? null)) {
            $probabilities = [];
            foreach ($answer['probabilities'] as $option => $probability) {
                if (is_numeric($probability)) {
                    $probabilities[(string) $option] = (float) $probability;
                }
            }
        }

        $score = $answer['score'] ?? null;

        return new self(
            choice: is_scalar($answer['choice'] ?? null) ? (string) $answer['choice'] : null,
            probabilities: $probabilities,
            confidence: is_numeric($answer['confidence'] ?? null) ? (float) $answer['confidence'] : null,
            noul: is_numeric($answer['noul'] ?? null) ? (float) $answer['noul'] : null,
            score: is_int($score) || is_float($score) ? $score : null,
            legend: is_array($answer['legend'] ?? null) ? $answer['legend'] : null,
            raw: $answer,
        );
    }

    /**
     * The answer the service never gave for a question it was asked.
     */
    public static function missing(): self
    {
        return new self(error: 'missing', message: 'The service returned no answer for this question.');
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw !== [] ? $this->raw : array_filter([
            'error' => $this->error,
            'message' => $this->message,
        ], static fn (?string $value): bool => $value !== null);
    }
}
