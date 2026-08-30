<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

final readonly class CandidateHypothesis
{
    /** @param list<CandidateSignal> $signals */
    public function __construct(
        public CandidateIdentity $identity,
        public CandidateMetadata $metadata,
        public array $signals,
    ) {}

    public function distinctRecordingSupport(): int
    {
        $recordingIds = array_filter(array_map(
            static fn (CandidateSignal $signal): ?string => $signal->identity->recordingId,
            $this->signals,
        ));

        return count(array_unique($recordingIds));
    }

    public function independentEvidenceSupport(): int
    {
        return count(array_unique(array_map(
            static fn (CandidateSignal $signal): string => $signal->provenanceFamily,
            $this->signals,
        )));
    }
}
