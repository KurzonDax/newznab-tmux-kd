<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

final readonly class CandidateSummary
{
    /**
     * @param  array<string, scalar|null>  $displaySnapshot
     * @param  array<string, int|float|string|bool|null>  $featureVector
     * @param  array<string, int>  $scoreContributions
     * @param  list<string>  $contradictions
     * @param  list<string>  $provenanceFamilies
     * @param  list<string>  $responseCacheKeys
     */
    public function __construct(
        public CandidateIdentity $identity,
        public int $score,
        public array $displaySnapshot,
        public array $featureVector,
        public array $scoreContributions,
        public array $contradictions,
        public array $provenanceFamilies,
        public array $responseCacheKeys = [],
    ) {}
}
