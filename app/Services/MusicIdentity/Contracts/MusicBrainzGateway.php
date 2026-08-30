<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Contracts;

use App\Services\MusicIdentity\DTO\CandidateIdentifiers;
use App\Services\MusicIdentity\DTO\CandidateMetadata;
use App\Services\MusicIdentity\DTO\RecordingCandidates;
use App\Services\MusicIdentity\DTO\RecordingQuery;
use App\Services\MusicIdentity\DTO\ReleaseCandidates;
use App\Services\MusicIdentity\DTO\ReleaseQuery;

interface MusicBrainzGateway
{
    public function candidatesFor(RecordingQuery $query): RecordingCandidates;

    public function releaseCandidatesFor(ReleaseQuery $query): ReleaseCandidates;

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata;
}
