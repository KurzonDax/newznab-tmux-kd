<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

/** @phpstan-import-type MusicRecording from CandidateMetadata */
final readonly class RecordingCandidates
{
    /**
     * Provider-neutral candidate records. MusicBrainz's hyphenated response
     * keys and nested list wrappers never cross this boundary.
     *
     * @param  list<MusicRecording>  $recordings
     * @param  list<string>  $responseCacheKeys
     */
    public function __construct(
        public array $recordings,
        public int $providerTotal = 0,
        public array $responseCacheKeys = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }
}
