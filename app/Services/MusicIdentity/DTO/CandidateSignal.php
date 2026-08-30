<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

use App\Services\MusicIdentity\Enums\CandidateSignalKind;

final readonly class CandidateSignal
{
    public function __construct(
        public CandidateSignalKind $kind,
        public string $value,
        public string $provenanceFamily,
        public bool $exact,
        public CandidateIdentity $identity = new CandidateIdentity,
        public ?int $providerScore = null,
    ) {}
}
