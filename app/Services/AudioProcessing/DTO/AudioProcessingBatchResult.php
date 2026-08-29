<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\DTO;

use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;

final readonly class AudioProcessingBatchResult
{
    /**
     * @param  list<AudioProcessingResult>  $results
     */
    public function __construct(public array $results) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function pickedCount(): int
    {
        return count($this->results);
    }

    public function previewCount(): int
    {
        return count(array_filter(
            $this->results,
            static fn (AudioProcessingResult $result): bool => $result->previewCreated,
        ));
    }

    public function declinedCount(): int
    {
        return count(array_filter(
            $this->results,
            static fn (AudioProcessingResult $result): bool => $result->outcome === ProcessingOutcome::DeclinedToVideoPath,
        ));
    }

    public function crcFailureCount(): int
    {
        return array_sum(array_map(
            static fn (AudioProcessingResult $result): int => $result->crcFailures,
            $this->results,
        ));
    }

    /**
     * @return array<string, int>
     */
    public function outcomeCounts(): array
    {
        return array_count_values(array_map(
            static fn (AudioProcessingResult $result): string => $result->outcome->value,
            $this->results,
        ));
    }

    /**
     * @return array<string, int>
     */
    public function reasonCounts(): array
    {
        return array_count_values(array_map(
            static fn (AudioProcessingResult $result): string => $result->reason,
            array_filter(
                $this->results,
                static fn (AudioProcessingResult $result): bool => $result->outcome !== ProcessingOutcome::Completed,
            ),
        ));
    }
}
