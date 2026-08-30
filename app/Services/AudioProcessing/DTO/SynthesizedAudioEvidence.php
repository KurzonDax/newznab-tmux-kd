<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\DTO;

/**
 * Complete local observations prepared for one lazy evidence revision.
 *
 * @phpstan-type NzbEvidenceFacts array{size?: int, extension?: string, parts_total?: int}
 * @phpstan-type ReleaseFileFacts array{size?: int, passworded?: bool, crc32?: string}
 * @phpstan-type ReleaseFileManifestEntry array{
 *     source: 'release_file',
 *     ordinal: int,
 *     name: string,
 *     facts: ReleaseFileFacts
 * }
 * @phpstan-type SidecarManifestEntry array{
 *     source: 'nzb'|'release_file',
 *     ordinal: int,
 *     filename: string,
 *     segment_count: int|null,
 *     kind: 'cue'|'playlist'|'eac_log',
 *     facts: NzbEvidenceFacts|ReleaseFileFacts
 * }
 * @phpstan-type SampledTags array{
 *     source_file: string|null,
 *     raw_tags: array<string, mixed>,
 *     album: string|null,
 *     album_performer: string|null,
 *     performer: string|null,
 *     track_name: string|null,
 *     recorded_date: string|null,
 *     container_format: string|null,
 *     disc_position: int|null,
 *     track_position: int|null,
 *     isrc: string|null,
 *     barcode: string|null,
 *     catalog_number: string|null,
 *     disc_id: string|null,
 *     musicbrainz_recording_id: string|null,
 *     musicbrainz_track_id: string|null,
 *     musicbrainz_album_id: string|null,
 *     musicbrainz_release_group_id: string|null,
 *     musicbrainz_artist_id: string|null
 * }
 */
final readonly class SynthesizedAudioEvidence
{
    /**
     * @param  list<AudioEvidenceFile>  $nzbManifest
     * @param  list<AudioEvidenceFile>  $releaseAudioFiles
     * @param  list<ReleaseFileManifestEntry>  $releaseFileManifest
     * @param  list<SidecarManifestEntry>  $sidecarManifest
     * @param  SampledTags|null  $sampledTags
     */
    public function __construct(
        public array $nzbManifest,
        public array $releaseAudioFiles,
        public array $releaseFileManifest,
        public array $sidecarManifest,
        public ?array $sampledTags,
    ) {}
}
