<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Contracts;

use App\Services\MusicIdentity\DTO\CandidateIdentifiers;
use App\Services\MusicIdentity\DTO\CandidateMetadata;
use App\Services\MusicIdentity\DTO\RecordingCandidates;
use App\Services\MusicIdentity\DTO\RecordingQuery;

interface MusicBrainzGateway
{
    public function candidatesFor(RecordingQuery $query): RecordingCandidates;

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata;
}
