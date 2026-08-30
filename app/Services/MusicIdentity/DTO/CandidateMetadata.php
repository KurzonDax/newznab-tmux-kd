<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

/**
 * @phpstan-type MusicRecording array{
 *     recordingId: string,
 *     title: string,
 *     artistCredit: string|null,
 *     lengthMs: int|null,
 *     video: bool|null,
 *     isrcs: list<string>,
 *     releaseIds: list<string>,
 *     releaseGroupIds: list<string>,
 *     providerScore: int|null,
 *     sources: list<string>
 * }
 * @phpstan-type MusicLabel array{catalogNumber: string|null, labelId: string|null, labelName: string|null}
 * @phpstan-type MusicBrainzReleaseTrack array{
 *     musicBrainzReleaseTrackId: string,
 *     title: string,
 *     position: int|null,
 *     number: string|null,
 *     lengthMs: int|null,
 *     artistCredit: string|null,
 *     recording: MusicRecording|null
 * }
 * @phpstan-type MusicMedium array{
 *     position: int|null,
 *     title: string|null,
 *     format: string|null,
 *     releaseTrackCount: int|null,
 *     discIds: list<string>,
 *     releaseTracks: list<MusicBrainzReleaseTrack>
 * }
 * @phpstan-type MusicRelease array{
 *     releaseId: string,
 *     title: string,
 *     artistCredit: string|null,
 *     releaseGroupId: string|null,
 *     status: string|null,
 *     date: string|null,
 *     country: string|null,
 *     barcode: string|null,
 *     labels: list<MusicLabel>,
 *     media: list<MusicMedium>
 * }
 * @phpstan-type MusicReleaseGroup array{
 *     releaseGroupId: string,
 *     title: string,
 *     artistCredit: string|null,
 *     primaryType: string|null,
 *     secondaryTypes: list<string>,
 *     firstReleaseDate: string|null
 * }
 * @phpstan-type MusicArtist array{
 *     artistId: string,
 *     name: string,
 *     sortName: string,
 *     disambiguation: string|null,
 *     type: string|null,
 *     country: string|null
 * }
 */
final readonly class CandidateMetadata
{
    /**
     * @param  list<MusicRecording>  $recordings
     * @param  list<MusicRelease>  $releases
     * @param  list<MusicReleaseGroup>  $releaseGroups
     * @param  list<MusicArtist>  $artists
     */
    public function __construct(
        public array $recordings,
        public array $releases,
        public array $releaseGroups,
        public array $artists = [],
    ) {}

    public static function empty(): self
    {
        return new self([], [], [], []);
    }
}
