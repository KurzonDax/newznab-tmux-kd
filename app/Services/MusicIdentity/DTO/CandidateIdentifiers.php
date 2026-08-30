<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

use App\Services\MusicIdentity\Support\MusicIdentityValueNormalizer;

/**
 * @phpstan-type NormalizedCandidateIdentifiers array{
 *     artistId: string|null,
 *     discId: string|null,
 *     isrc: string|null,
 *     recordingId: string|null,
 *     releaseGroupId: string|null,
 *     releaseId: string|null
 * }
 */
final readonly class CandidateIdentifiers
{
    public function __construct(
        public ?string $recordingId = null,
        public ?string $releaseId = null,
        public ?string $releaseGroupId = null,
        public ?string $isrc = null,
        public ?string $discId = null,
        public ?string $artistId = null,
    ) {}

    /** @return NormalizedCandidateIdentifiers */
    public function normalized(): array
    {
        return [
            'artistId' => MusicIdentityValueNormalizer::identifier($this->artistId),
            'discId' => MusicIdentityValueNormalizer::text($this->discId),
            'isrc' => MusicIdentityValueNormalizer::identifier($this->isrc, uppercase: true),
            'recordingId' => MusicIdentityValueNormalizer::identifier($this->recordingId),
            'releaseGroupId' => MusicIdentityValueNormalizer::identifier($this->releaseGroupId),
            'releaseId' => MusicIdentityValueNormalizer::identifier($this->releaseId),
        ];
    }
}
