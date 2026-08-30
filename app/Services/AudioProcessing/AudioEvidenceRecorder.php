<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Models\Release;
use App\Models\ReleaseAudioEvidence;
use App\Services\AdditionalProcessing\PostedFileClassifier;
use App\Services\AudioProcessing\DTO\AudioFetchResult;
use App\Services\AudioProcessing\DTO\AudioSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Commits one append-only collection of observations from the audio path.
 *
 * Revision allocation, evidence hashing, filename inference and persistence
 * live behind this interface so callers cannot partially write the ledger.
 */
final class AudioEvidenceRecorder
{
    private const int SCHEMA_VERSION = 1;

    /**
     * @param  array<string, mixed>|null  $sampledTags
     */
    public function record(
        Release $release,
        AudioSource $source,
        AudioFetchResult $fetchResult,
        ?array $sampledTags,
        string $provenance = 'captured',
    ): ReleaseAudioEvidence {
        $nzbManifest = array_map(
            static fn ($file): array => $file->toArray(),
            $source->nzbAudioFiles,
        );
        $sidecarManifest = array_merge(array_map(
            static fn ($file): array => $file->toArray(),
            $source->sidecars,
        ), $this->archiveSidecars($fetchResult->archiveMembers));
        $archiveManifest = array_values($fetchResult->archiveMembers);
        $snapshot = $this->releaseSnapshot($release);
        $tracks = $this->tracks($source, $fetchResult, $sampledTags);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'provenance' => $provenance,
            'release_snapshot' => $snapshot,
            'archive_manifest_complete' => $fetchResult->archiveManifestComplete,
            'source_file_complete' => $fetchResult->sourceFileComplete,
            'source_starts_at_zero' => $fetchResult->sourceStartsAtZero,
            'whole_duration_reliable' => $fetchResult->wholeDurationReliable,
            'only_one_track_probed' => $fetchResult->onlyOneTrackProbed,
            'nzb_manifest' => $nzbManifest,
            'archive_manifest' => $archiveManifest,
            'sidecar_manifest' => $sidecarManifest,
            'tracks' => $tracks,
        ];
        $evidenceHash = hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));

        return DB::transaction(function () use (
            $release,
            $payload,
            $tracks,
            $evidenceHash,
        ): ReleaseAudioEvidence {
            $releaseLock = Release::query()->whereKey($release->id);
            if (DB::getDriverName() !== 'sqlite') {
                $releaseLock->lockForUpdate();
            }
            $releaseLock->value('id');

            $revisionQuery = ReleaseAudioEvidence::query()
                ->where('releases_id', $release->id)
                ->orderByDesc('revision');

            $revision = ((int) $revisionQuery->value('revision')) + 1;
            $evidence = ReleaseAudioEvidence::query()->create([
                'releases_id' => (int) $release->id,
                'revision' => $revision,
                'evidence_hash' => $evidenceHash,
                'schema_version' => $payload['schema_version'],
                'provenance' => $payload['provenance'],
                'release_snapshot' => $payload['release_snapshot'],
                'archive_manifest_complete' => $payload['archive_manifest_complete'],
                'source_file_complete' => $payload['source_file_complete'],
                'source_starts_at_zero' => $payload['source_starts_at_zero'],
                'whole_duration_reliable' => $payload['whole_duration_reliable'],
                'only_one_track_probed' => $payload['only_one_track_probed'],
                'nzb_manifest' => $payload['nzb_manifest'],
                'archive_manifest' => $payload['archive_manifest'],
                'sidecar_manifest' => $payload['sidecar_manifest'],
                'captured_at' => now(),
            ]);

            if ($tracks !== []) {
                $evidence->tracks()->createMany($tracks);
            }

            return $evidence;
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseSnapshot(Release $release): array
    {
        $postdate = $release->postdate;
        if ($postdate instanceof \DateTimeInterface) {
            $postdate = $postdate->format(DATE_ATOM);
        } elseif ($postdate !== null) {
            $postdate = (string) $postdate;
        }

        return [
            'release_id' => (int) $release->id,
            'guid' => (string) $release->guid,
            'name' => (string) $release->name,
            'searchname' => (string) $release->searchname,
            'categories_id' => (int) $release->categories_id,
            'groups_id' => (int) $release->groups_id,
            'postdate' => $postdate,
            'size' => (int) $release->size,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $archiveMembers
     * @return list<array<string, mixed>>
     */
    private function archiveSidecars(array $archiveMembers): array
    {
        $sidecars = [];
        foreach ($archiveMembers as $index => $member) {
            $filename = (string) ($member['name'] ?? '');
            $kind = $this->sidecarKind($filename);
            if ($kind === null) {
                continue;
            }

            $sidecars[] = [
                'source' => 'archive',
                'ordinal' => $index + 1,
                'filename' => $filename,
                'segment_count' => null,
                'kind' => $kind,
                'facts' => array_filter([
                    'size' => isset($member['size']) ? (int) $member['size'] : null,
                    'compressed' => isset($member['compressed']) ? (bool) $member['compressed'] : null,
                ], static fn (mixed $value): bool => $value !== null),
            ];
        }

        return $sidecars;
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
     * @param  array<string, mixed>|null  $sampledTags
     * @return list<array<string, mixed>>
     */
    private function tracks(AudioSource $source, AudioFetchResult $fetchResult, ?array $sampledTags): array
    {
        $tracks = [];

        foreach ($source->nzbAudioFiles as $file) {
            $tracks[] = $this->track(
                sourceKind: 'nzb',
                sourceOrdinal: $file->ordinal,
                filename: $file->filename,
                segmentCount: $file->segmentCount,
            );
        }

        foreach ($fetchResult->archiveMembers as $index => $member) {
            $filename = (string) ($member['name'] ?? '');
            if ($filename === '' || ! PostedFileClassifier::matchesTerminalExtension(
                $filename,
                AudioProcessingConfiguration::AUDIO_FILE_REGEX,
            )) {
                continue;
            }

            $tracks[] = $this->track(
                sourceKind: 'archive',
                sourceOrdinal: $index + 1,
                filename: $filename,
            );
        }

        if ($sampledTags === null && $fetchResult->sampledFilename === null) {
            return $tracks;
        }

        $sampledFilename = (string) ($fetchResult->sampledFilename ?? $sampledTags['source_file'] ?? 'sampled-audio');
        $sampledPathKey = $this->filenameKey($sampledFilename);
        $pathMatches = [];
        foreach ($tracks as $index => $track) {
            if ($this->filenameKey((string) $track['raw_filename']) === $sampledPathKey) {
                $pathMatches[] = $index;
            }
        }
        $sampledIndex = count($pathMatches) === 1 ? $pathMatches[0] : null;

        if ($sampledIndex === null) {
            $sampledBasenameKey = $this->basenameKey($sampledFilename);
            $basenameMatches = [];
            foreach ($tracks as $index => $track) {
                if ($this->basenameKey((string) $track['raw_filename']) === $sampledBasenameKey) {
                    $basenameMatches[] = $index;
                }
            }
            $sampledIndex = count($basenameMatches) === 1 ? $basenameMatches[0] : null;
        }

        if ($sampledIndex === null) {
            $tracks[] = $this->track(
                sourceKind: 'sampled',
                sourceOrdinal: count($tracks) + 1,
                filename: $sampledFilename,
            );
            $sampledIndex = array_key_last($tracks);
        }

        $tracks[$sampledIndex] = array_merge($tracks[$sampledIndex], $this->sampledTrackFacts(
            $sampledTags ?? [],
            $fetchResult,
            $tracks[$sampledIndex],
        ));

        return array_values($tracks);
    }

    /**
     * @return array<string, mixed>
     */
    private function track(
        string $sourceKind,
        int $sourceOrdinal,
        string $filename,
        ?int $segmentCount = null,
    ): array {
        $hints = $this->filenameHints($filename);

        return [
            'source_kind' => $sourceKind,
            'source_ordinal' => $sourceOrdinal,
            'source_path' => $this->sourcePath($filename),
            'raw_filename' => mb_substr($filename, 0, 512),
            'segment_count' => $segmentCount,
            'disc_number' => $hints['disc_number'],
            'track_number' => $hints['track_number'],
            'normalized_title_hint' => $hints['title'],
            'normalized_artist_hint' => $hints['artist'],
        ];
    }

    /**
     * @param  array<string, mixed>  $tags
     * @param  array<string, mixed>  $baseTrack
     * @return array<string, mixed>
     */
    private function sampledTrackFacts(array $tags, AudioFetchResult $fetchResult, array $baseTrack): array
    {
        return [
            'disc_number' => $this->positiveInteger($tags['disc_position'] ?? null) ?? $baseTrack['disc_number'],
            'track_number' => $this->positiveInteger($tags['track_position'] ?? null) ?? $baseTrack['track_number'],
            'normalized_title_hint' => $this->normalizedText($tags['track_name'] ?? null)
                ?? $baseTrack['normalized_title_hint'],
            'normalized_artist_hint' => $this->normalizedText(
                $tags['album_performer'] ?? $tags['performer'] ?? null,
            ) ?? $baseTrack['normalized_artist_hint'],
            'raw_tags' => is_array($tags['raw_tags'] ?? null) ? $tags['raw_tags'] : null,
            'album' => $this->limitedText($tags['album'] ?? null),
            'album_artist' => $this->limitedText($tags['album_performer'] ?? null),
            'performer' => $this->limitedText($tags['performer'] ?? null),
            'title' => $this->limitedText($tags['track_name'] ?? null),
            'recorded_date' => $this->limitedText($tags['recorded_date'] ?? null, 32),
            'normalized_album' => $this->normalizedText($tags['album'] ?? null),
            'normalized_album_artist' => $this->normalizedText($tags['album_performer'] ?? null),
            'normalized_performer' => $this->normalizedText($tags['performer'] ?? null),
            'normalized_title' => $this->normalizedText($tags['track_name'] ?? null),
            'normalized_date' => $this->normalizedText($tags['recorded_date'] ?? null),
            'container' => $this->limitedText($tags['container_format'] ?? $fetchResult->extension, 50),
            'codec' => $this->limitedText($tags['codec'] ?? null, 50),
            'whole_duration_seconds' => $this->positiveFloat($tags['duration_seconds'] ?? null),
            'decoded_duration_seconds' => $fetchResult->decodedDurationSeconds,
            'source_file_complete' => $fetchResult->sourceFileComplete,
            'source_starts_at_zero' => $fetchResult->sourceStartsAtZero,
            'whole_duration_reliable' => $fetchResult->wholeDurationReliable,
            'isrc' => $this->limitedText($tags['isrc'] ?? null, 64),
            'musicbrainz_track_id' => $this->limitedText($tags['musicbrainz_track_id'] ?? null, 36),
            'musicbrainz_recording_id' => $this->limitedText($tags['musicbrainz_recording_id'] ?? null, 36),
            'musicbrainz_release_id' => $this->limitedText($tags['musicbrainz_album_id'] ?? null, 36),
            'musicbrainz_release_group_id' => $this->limitedText($tags['musicbrainz_release_group_id'] ?? null, 36),
            'musicbrainz_artist_id' => $this->limitedText($tags['musicbrainz_artist_id'] ?? null, 36),
            'barcode' => $this->limitedText($tags['barcode'] ?? null, 64),
            'catalog_number' => $this->limitedText($tags['catalog_number'] ?? null, 128),
            'disc_id_like' => $this->limitedText($tags['disc_id'] ?? null),
        ];
    }

    /**
     * @return array{disc_number: int|null, track_number: int|null, title: string|null, artist: string|null}
     */
    private function filenameHints(string $filename): array
    {
        $path = str_replace('\\', '/', $this->cleanFilename($filename));
        $discNumber = null;
        if (preg_match('~(?:^|/)(?:cd|disc)[ ._-]?(\d{1,3})(?:/|$)~i', $path, $discMatch) === 1) {
            $discNumber = (int) $discMatch[1];
        }

        $stem = pathinfo(basename($path), PATHINFO_FILENAME);
        $trackNumber = null;
        if (preg_match('/^(?:(?:cd|disc)[ ._-]*\d{1,3}[ ._-]+)?(?:track[ ._-]*)?(\d{1,3})(?:\s*[-._]\s*|\s+)/i', $stem, $trackMatch) === 1) {
            $trackNumber = (int) $trackMatch[1];
            $stem = (string) preg_replace('/^(?:(?:cd|disc)[ ._-]*\d{1,3}[ ._-]+)?(?:track[ ._-]*)?\d{1,3}(?:\s*[-._]\s*|\s+)/i', '', $stem);
        }

        $artist = null;
        $title = $stem;
        $parts = preg_split('/\s+-\s+/', $stem, 2) ?: [];
        if (count($parts) === 2) {
            [$artist, $title] = $parts;
        }

        return [
            'disc_number' => $discNumber,
            'track_number' => $trackNumber,
            'title' => $this->normalizedText($title),
            'artist' => $this->normalizedText($artist),
        ];
    }

    private function sourcePath(string $filename): ?string
    {
        $path = str_replace('\\', '/', $this->cleanFilename($filename));
        $directory = dirname($path);

        return $directory === '.' ? null : mb_substr($directory, 0, 512);
    }

    private function filenameKey(string $filename): string
    {
        return mb_strtolower(trim(str_replace('\\', '/', $this->cleanFilename($filename)), '/'));
    }

    private function basenameKey(string $filename): string
    {
        return basename($this->filenameKey($filename));
    }

    private function cleanFilename(string $filename): string
    {
        if (preg_match_all('/"([^"]+)"/', $filename, $matches) > 0) {
            return trim((string) $matches[1][array_key_last($matches[1])]);
        }

        return trim((string) preg_replace('/\s+yEnc(?:\s.*)?$/i', '', $filename), " \t\n\r\0\x0B\"");
    }

    private function normalizedText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = mb_strtolower(Str::ascii(trim((string) $value)));
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $normalized));

        return $normalized === '' ? null : mb_substr($normalized, 0, 255);
    }

    private function limitedText(mixed $value, int $length = 255): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            return null;
        }

        return min((int) $value, 65535);
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return round((float) $value, 3);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
