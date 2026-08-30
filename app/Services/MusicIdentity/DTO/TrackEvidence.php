<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

final readonly class TrackEvidence
{
    public function __construct(
        public int $evidenceTrackId,
        public string $sourceKind,
        public int $sourceOrdinal,
        public string $rawFilename,
        public ?string $title = null,
        public ?string $artist = null,
        public ?int $durationMs = null,
        public ?string $recordingId = null,
        public ?string $releaseId = null,
        public ?string $releaseGroupId = null,
        public ?string $musicBrainzReleaseTrackId = null,
        public ?string $artistId = null,
        public ?string $isrc = null,
        public ?string $discId = null,
        public ?string $discToc = null,
        public ?string $barcode = null,
        public ?string $catalogNumber = null,
        public ?string $label = null,
        public ?string $provenanceFamily = null,
    ) {}
}
