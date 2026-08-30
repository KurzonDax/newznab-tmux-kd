<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

use App\Services\MusicIdentity\Support\MusicIdentityValueNormalizer;

/**
 * @phpstan-type NormalizedRecordingQuery array{
 *     artist: string|null,
 *     discId: string|null,
 *     discToc: string|null,
 *     durationMs: int|null,
 *     durationToleranceMs: int,
 *     isrc: string|null,
 *     limit: int|null,
 *     offset: int,
 *     recordingId: string|null,
 *     releaseTitle: string|null,
 *     title: string|null,
 *     musicBrainzReleaseTrackId: string|null,
 *     artistId: string|null,
 *     fuzzy: bool
 * }
 */
final readonly class RecordingQuery
{
    public function __construct(
        public ?string $recordingId = null,
        public ?string $isrc = null,
        public ?string $discId = null,
        public ?string $discToc = null,
        public ?string $title = null,
        public ?string $artist = null,
        public ?string $releaseTitle = null,
        public ?int $durationMs = null,
        public int $durationToleranceMs = 5_000,
        public ?int $limit = null,
        public int $offset = 0,
        public ?string $musicBrainzReleaseTrackId = null,
        public ?string $artistId = null,
        public bool $fuzzy = false,
    ) {}

    /** @return NormalizedRecordingQuery */
    public function normalized(): array
    {
        return [
            'artist' => MusicIdentityValueNormalizer::text($this->artist),
            'discId' => MusicIdentityValueNormalizer::text($this->discId),
            'discToc' => MusicIdentityValueNormalizer::text($this->discToc),
            'durationMs' => $this->durationMs === null ? null : max(0, $this->durationMs),
            'durationToleranceMs' => max(0, $this->durationToleranceMs),
            'isrc' => MusicIdentityValueNormalizer::identifier($this->isrc, uppercase: true),
            'limit' => $this->limit === null ? null : min(100, max(1, $this->limit)),
            'offset' => max(0, $this->offset),
            'recordingId' => MusicIdentityValueNormalizer::identifier($this->recordingId),
            'releaseTitle' => MusicIdentityValueNormalizer::text($this->releaseTitle),
            'title' => MusicIdentityValueNormalizer::text($this->title),
            'musicBrainzReleaseTrackId' => MusicIdentityValueNormalizer::identifier($this->musicBrainzReleaseTrackId),
            'artistId' => MusicIdentityValueNormalizer::identifier($this->artistId),
            'fuzzy' => $this->fuzzy,
        ];
    }
}
