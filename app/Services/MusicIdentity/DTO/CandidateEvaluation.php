<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

final readonly class CandidateEvaluation
{
    /**
     * @param  array<string, int|float|string|bool|null>  $features
     * @param  array<string, int>  $contributions
     * @param  list<string>  $contradictions
     */
    public function __construct(
        public CandidateHypothesis $candidate,
        public int $score,
        public array $features,
        public array $contributions,
        public array $contradictions,
        public AlignmentResult $alignment,
        public CandidateIdentity $alignedIdentity,
        public CandidateSummary $summary,
    ) {}

    public function hasHardContradiction(): bool
    {
        return $this->contradictions !== [];
    }
}
