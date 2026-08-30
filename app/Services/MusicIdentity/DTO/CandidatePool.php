<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

final readonly class CandidatePool
{
    /** @param list<CandidateHypothesis> $candidates */
    public function __construct(public array $candidates) {}
}
