<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

use App\Services\MusicIdentity\Enums\IdentificationBand;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use DateTimeInterface;

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
        public ?AcceptedMusicText $acceptedText = null,
        public ?DateTimeInterface $acoustIdLookedUpAt = null,
    ) {}

    /** The same decision, reached after consulting fingerprint lookups at the given time. */
    public function withAcoustIdLookedUpAt(DateTimeInterface $lookedUpAt): self
    {
        return new self(
            status: $this->status,
            score: $this->score,
            band: $this->band,
            acceptedIdentity: $this->acceptedIdentity,
            reasons: $this->reasons,
            candidates: $this->candidates,
            runnerUpMargin: $this->runnerUpMargin,
            algorithmVersion: $this->algorithmVersion,
            resolverVersion: $this->resolverVersion,
            normalizerVersion: $this->normalizerVersion,
            scorerVersion: $this->scorerVersion,
            policyVersion: $this->policyVersion,
            operationalError: $this->operationalError,
            acceptedText: $this->acceptedText,
            acoustIdLookedUpAt: $lookedUpAt,
        );
    }
}
