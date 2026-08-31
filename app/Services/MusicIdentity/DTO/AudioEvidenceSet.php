<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

final readonly class AudioEvidenceSet
{
    /** @param list<TrackEvidence> $trackEvidence */
    public function __construct(
        public int $evidenceId,
        public string $evidenceHash,
        public ?string $releaseTitle,
        public ?string $albumTitle,
        public ?string $albumArtist,
        public ?int $releaseYear,
        public array $trackEvidence,
        public ?bool $trackEvidenceListComplete = null,
        public ?string $albumProvenanceFamily = null,
        public ?int $mediumCount = null,
        public ?string $country = null,
        public ?string $mediaFormat = null,
        public ?string $releaseStatus = null,
        public ?string $primaryType = null,
        /** @var list<string> */
        public array $secondaryTypes = [],
    ) {}

    public function albumProvenanceFamily(): string
    {
        return $this->albumProvenanceFamily ?? 'evidence:'.$this->evidenceId;
    }

    public function provenanceFamilyFor(TrackEvidence $trackEvidence): string
    {
        if ($this->albumTitle !== null && $this->albumProvenanceFamily === null) {
            return $this->albumProvenanceFamily();
        }

        return $trackEvidence->provenanceFamily
            ?? $this->albumProvenanceFamily();
    }
}
