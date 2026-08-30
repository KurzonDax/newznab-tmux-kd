<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Enums\NzbParseFailure;
use App\Models\Release;
use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseAudioTag;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\PostedFileClassifier;
use App\Services\AudioProcessing\DTO\AudioEvidenceFile;
use App\Services\AudioProcessing\DTO\SynthesizedAudioEvidence;
use Illuminate\Support\Facades\DB;

/**
 * Reconstructs the first evidence revision from durable local release data.
 *
 * This is the resolver's lazy back-catalog seam. It never fetches audio or
 * opens an NNTP connection, and the recorder owns the final missing-row check
 * so concurrent resolution cannot append duplicate synthesized revisions.
 *
 * @phpstan-import-type ReleaseFileManifestEntry from SynthesizedAudioEvidence
 * @phpstan-import-type SidecarManifestEntry from SynthesizedAudioEvidence
 * @phpstan-import-type SampledTags from SynthesizedAudioEvidence
 */
final readonly class AudioEvidenceSynthesizer
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private NzbContentParser $nzbParser,
        private AudioSourceSelector $sourceSelector,
        private AudioEvidenceRecorder $recorder,
    ) {}

    public function synthesizeIfMissing(Release $release): ReleaseAudioEvidence
    {
        $existing = ReleaseAudioEvidence::query()
            ->where('releases_id', $release->id)
            ->orderByDesc('revision')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $parsed = $this->nzbParser->parseNzb((string) $release->guid);
        if ($parsed['failure'] === NzbParseFailure::StorageUnavailable) {
            throw new \RuntimeException($parsed['error'] ?? 'Stored NZB could not be read for evidence synthesis.');
        }

        $contents = array_values($parsed['contents']);
        $nzbManifest = $this->sourceSelector->evidenceFiles($contents);
        $sidecars = array_map(
            static fn (AudioEvidenceFile $file): array => $file->toArray(),
            array_values(array_filter(
                $nzbManifest,
                static fn (AudioEvidenceFile $file): bool => in_array($file->kind, ['cue', 'playlist', 'eac_log'], true),
            )),
        );
        [$releaseAudioFiles, $releaseFileManifest, $releaseSidecars] = $this->releaseFiles($release);
        $tags = $this->sampledTags(ReleaseAudioTag::query()->where('releases_id', $release->id)->first());

        return $this->recorder->recordSynthesizedIfMissing(
            $release,
            new SynthesizedAudioEvidence(
                nzbManifest: $nzbManifest,
                releaseAudioFiles: $releaseAudioFiles,
                releaseFileManifest: $releaseFileManifest,
                sidecarManifest: array_merge($sidecars, $releaseSidecars),
                sampledTags: $tags,
            ),
        );
    }

    /**
     * @return array{
     *     list<AudioEvidenceFile>,
     *     list<ReleaseFileManifestEntry>,
     *     list<SidecarManifestEntry>
     * }
     */
    private function releaseFiles(Release $release): array
    {
        $rows = DB::table('release_files')
            ->where('releases_id', $release->id)
            ->orderBy('name')
            ->get(['name', 'size', 'passworded', 'crc32']);
        $audioFiles = [];
        $manifest = [];
        $sidecars = [];

        foreach ($rows as $index => $row) {
            $ordinal = $index + 1;
            $filename = (string) $row->name;
            $facts = array_filter([
                'size' => isset($row->size) ? (int) $row->size : null,
                'passworded' => isset($row->passworded) ? (bool) $row->passworded : null,
                'crc32' => $this->text($row->crc32 ?? null),
            ], static fn (mixed $value): bool => $value !== null);
            $manifest[] = [
                'source' => 'release_file',
                'ordinal' => $ordinal,
                'name' => $filename,
                'facts' => $facts,
            ];

            if (PostedFileClassifier::matchesTerminalExtension(
                $filename,
                AudioProcessingConfiguration::AUDIO_FILE_REGEX,
            )) {
                $audioFiles[] = new AudioEvidenceFile(
                    ordinal: $ordinal,
                    filename: $filename,
                    segmentCount: null,
                    kind: 'audio',
                    facts: $facts,
                    source: 'release_file',
                );

                continue;
            }

            $kind = $this->sidecarKind($filename);
            if ($kind !== null) {
                $sidecars[] = [
                    'source' => 'release_file',
                    'ordinal' => $ordinal,
                    'filename' => $filename,
                    'segment_count' => null,
                    'kind' => $kind,
                    'facts' => $facts,
                ];
            }
        }

        return [$audioFiles, $manifest, $sidecars];
    }

    private function sidecarKind(string $filename): ?string
    {
        return match (true) {
            PostedFileClassifier::matchesTerminalExtension($filename, '\\.CUE') => 'cue',
            PostedFileClassifier::matchesTerminalExtension($filename, '\\.(M3U|M3U8|PLS)') => 'playlist',
            PostedFileClassifier::matchesTerminalExtension($filename, '\\.LOG') => 'eac_log',
            default => null,
        };
    }

    /**
     * Rehydrate the durable preview projection without inventing durations or
     * treating a release-track MBID as the historically overloaded recording ID.
     *
     * @return SampledTags|null
     */
    private function sampledTags(?ReleaseAudioTag $tag): ?array
    {
        if ($tag === null) {
            return null;
        }

        $rawTags = is_array($tag->raw_tags) ? $tag->raw_tags : [];
        $candidates = $this->candidates($rawTags);
        $recordingId = $this->uuidCandidate($candidates, ['musicbrainzrecordingid', 'musicbrainztrackid']);
        $trackId = $this->uuidCandidate($candidates, ['musicbrainzreleasetrackid']);
        if ($recordingId === null && $trackId === null) {
            $recordingId = $this->uuid($tag->musicbrainz_track_id);
        }

        $sampledTags = [
            'source_file' => $tag->source_file,
            'raw_tags' => $rawTags,
            'album' => $tag->album,
            'album_performer' => $tag->album_performer,
            'performer' => $tag->performer,
            'track_name' => $tag->track_name,
            'recorded_date' => $tag->recorded_date,
            'container_format' => $tag->audio_format,
            'disc_position' => $this->positionCandidate($candidates, ['partposition', 'discposition', 'discnumber', 'disc']),
            'track_position' => $tag->track_position,
            'isrc' => $this->firstCandidate($candidates, ['isrc', 'isrccode']),
            'barcode' => $this->firstCandidate($candidates, ['barcode', 'upc', 'ean']),
            'catalog_number' => $this->firstCandidate($candidates, ['catalognumber', 'catalogue', 'catalog']),
            'disc_id' => $this->firstCandidate($candidates, ['musicbrainzdiscid', 'discid', 'cddbid']),
            'musicbrainz_recording_id' => $recordingId,
            'musicbrainz_track_id' => $trackId,
            'musicbrainz_album_id' => $this->uuid($tag->musicbrainz_album_id)
                ?? $this->uuidCandidate($candidates, ['musicbrainzalbumid', 'musicbrainzreleaseid']),
            'musicbrainz_release_group_id' => $this->uuid($tag->musicbrainz_release_group_id)
                ?? $this->uuidCandidate($candidates, ['musicbrainzreleasegroupid']),
            'musicbrainz_artist_id' => $this->uuid($tag->musicbrainz_artist_id)
                ?? $this->uuidCandidate($candidates, ['musicbrainzartistid', 'musicbrainzalbumartistid']),
        ];

        foreach ($sampledTags as $key => $value) {
            if ($key === 'raw_tags') {
                if ($value !== []) {
                    return $sampledTags;
                }

                continue;
            }

            if ($value !== null && $value !== '') {
                return $sampledTags;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rawTags
     * @return array<string, string>
     */
    private function candidates(array $rawTags): array
    {
        $candidates = [];
        foreach ($rawTags as $key => $value) {
            if (is_array($value) && $this->normalizeKey((string) $key) === 'extra') {
                foreach ($value as $nestedKey => $nestedValue) {
                    $text = $this->text($nestedValue);
                    if ($text !== null) {
                        $candidates[$this->normalizeKey((string) $nestedKey)] ??= $text;
                    }
                }

                continue;
            }

            $text = $this->text($value);
            if ($text !== null) {
                $candidates[$this->normalizeKey((string) $key)] ??= $text;
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string, string>  $candidates
     * @param  list<string>  $aliases
     */
    private function firstCandidate(array $candidates, array $aliases): ?string
    {
        foreach ($aliases as $alias) {
            if (isset($candidates[$alias])) {
                return $candidates[$alias];
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $candidates
     * @param  list<string>  $aliases
     */
    private function uuidCandidate(array $candidates, array $aliases): ?string
    {
        return $this->uuid($this->firstCandidate($candidates, $aliases));
    }

    /**
     * @param  array<string, string>  $candidates
     * @param  list<string>  $aliases
     */
    private function positionCandidate(array $candidates, array $aliases): ?int
    {
        $position = $this->firstCandidate($candidates, $aliases);
        if ($position === null || preg_match('/\d+/', $position, $digits) !== 1) {
            return null;
        }

        return min((int) $digits[0], 65535);
    }

    private function uuid(mixed $value): ?string
    {
        $uuid = $this->text($value);

        return $uuid !== null && preg_match(self::UUID_PATTERN, $uuid) === 1
            ? strtolower($uuid)
            : null;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
    }

    private function text(mixed $value): ?string
    {
        while (is_array($value)) {
            $value = $value === [] ? null : reset($value);
        }

        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
