<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

use App\Services\MusicIdentity\Enums\IdentificationBand;
use App\Services\MusicIdentity\Enums\IdentificationStatus;

final readonly class IdentificationDecision
{
    /**
     * @param  list<DecisionReason>  $reasons
     * @param  list<CandidateSummary>  $candidates
     */
    public function __construct(
        public IdentificationStatus $status,
        public int $score,
        public IdentificationBand $band,
        public ?CandidateIdentity $acceptedIdentity,
        public array $reasons,
        public array $candidates,
        public ?int $runnerUpMargin,
        public string $algorithmVersion,
        public string $resolverVersion,
        public string $normalizerVersion,
        public string $scorerVersion,
        public string $policyVersion,
        public ?string $operationalError = null,
    ) {}
}
