<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

final readonly class CandidateIdentity
{
    public function __construct(
        public ?string $recordingId = null,
        public ?string $releaseId = null,
        public ?string $releaseGroupId = null,
    ) {}

    public function key(): string
    {
        if ($this->releaseId !== null) {
            return 'release:'.$this->releaseId;
        }
        if ($this->releaseGroupId !== null) {
            return 'release-group:'.$this->releaseGroupId;
        }

        return 'recording:'.$this->recordingId;
    }
}
