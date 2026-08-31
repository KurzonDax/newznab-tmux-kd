<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Contracts;

use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidatePool;

interface CandidateGenerator
{
    public function generate(AudioEvidenceSet $evidence): CandidatePool;
}
