<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Services\AdditionalProcessing\Enums\PayloadClassification;

final readonly class PayloadSniffMetrics
{
    /**
     * @param  array<string, int>  $classificationCounts
     */
    public function __construct(
        public int $candidateCount = 0,
        public array $classificationCounts = [],
    ) {}

    public function record(PayloadClassification $classification): self
    {
        $counts = $this->classificationCounts;
        $counts[$classification->value] = ($counts[$classification->value] ?? 0) + 1;

        return new self($this->candidateCount + 1, $counts);
    }

    public function merge(self $other): self
    {
        $counts = $this->classificationCounts;
        foreach ($other->classificationCounts as $classification => $count) {
            $counts[$classification] = ($counts[$classification] ?? 0) + $count;
        }

        return new self($this->candidateCount + $other->candidateCount, $counts);
    }
}
