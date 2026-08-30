<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

use App\Services\MusicIdentity\Enums\CandidateSignalKind;

final readonly class ExactRecordingIdentifierQuery
{
    public function __construct(
        public CandidateSignalKind $signalKind,
        public string $value,
        public RecordingQuery $query,
    ) {}
}
