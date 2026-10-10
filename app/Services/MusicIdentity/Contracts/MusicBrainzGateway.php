<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Contracts;

use App\Services\MusicIdentity\DTO\CandidateIdentifiers;
use App\Services\MusicIdentity\DTO\CandidateMetadata;
use App\Services\MusicIdentity\DTO\RecordingCandidates;
use App\Services\MusicIdentity\DTO\RecordingQuery;
use App\Services\MusicIdentity\DTO\ReleaseCandidates;
use App\Services\MusicIdentity\DTO\ReleaseGroupCandidates;
use App\Services\MusicIdentity\DTO\ReleaseGroupQuery;
use App\Services\MusicIdentity\DTO\ReleaseQuery;

/**
 * @phpstan-import-type MusicReleaseGroup from CandidateMetadata
 */
interface MusicBrainzGateway
{
    public function candidatesFor(RecordingQuery $query): RecordingCandidates;

    public function releaseCandidatesFor(ReleaseQuery $query): ReleaseCandidates;

    /** The release groups a search by artist and album title returns, in the provider's order. */
    public function releaseGroupCandidatesFor(ReleaseGroupQuery $query): ReleaseGroupCandidates;

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata;

    /**
     * One release group, looked up with the request hydration makes for it (so a group this
     * resolution already hydrated is a cache hit); its editions are not browsed. Null when
     * MusicBrainz has no such group or no endpoint is configured.
     *
     * @return MusicReleaseGroup|null
     */
    public function releaseGroup(string $releaseGroupId): ?array;
}
