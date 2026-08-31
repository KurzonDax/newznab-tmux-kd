<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

final readonly class AlignmentResult
{
    /** @param list<array{evidenceIndex: int, releaseTrackIndex: int, titleAgreement: float, artistCreditAgreement: float, artistCreditCompared: bool, durationAgreement: float, durationCompared: bool, perDiscPositionAgreement: float}> $matches */
    public function __construct(
        public array $matches,
        public int $observedCount,
        public int $candidateCount,
        public float $titleAgreement,
        public float $durationAgreement,
        public int $durationComparisonCount,
        public float $artistCreditAgreement,
        public int $artistCreditComparisonCount,
        public float $orderAgreement,
        public float $contiguousCoverage,
        public float $perDiscPositionAgreement,
        public float $observedCoverage,
        public float $candidateCoverage,
        public int $unmatchedObserved,
        public int $unmatchedCandidate,
    ) {}

    public static function empty(int $observedCount = 0, int $candidateCount = 0): self
    {
        return new self([], $observedCount, $candidateCount, 0.0, 0.0, 0, 0.0, 0, 0.0, 0.0, 0.0, 0.0, 0.0, $observedCount, $candidateCount);
    }
}
