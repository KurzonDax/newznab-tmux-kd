<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

/**
 * @phpstan-import-type MusicReleaseGroup from CandidateMetadata
 */
final readonly class ReleaseGroupCandidates
{
    /**
     * @param  list<MusicReleaseGroup>  $releaseGroups
     * @param  list<string>  $responseCacheKeys
     */
    public function __construct(
        public array $releaseGroups,
        public int $providerTotal = 0,
        public array $responseCacheKeys = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }
}
