<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

/**
 * One MusicBrainz recording linked to a fingerprint match, with the releases and release groups
 * the fingerprint service lists for it.
 */
final readonly class AcousticRecording
{
    /**
     * @param  list<string>  $releaseGroupIds
     * @param  array<string, string|null>  $releaseGroupByRelease  release ID => its release group ID when the service nests it
     */
    public function __construct(
        public string $recordingId,
        public array $releaseGroupIds = [],
        public array $releaseGroupByRelease = [],
    ) {}
}
