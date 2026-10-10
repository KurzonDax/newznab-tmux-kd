<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Evidence;

use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseAudioEvidenceTrack;
use App\Models\ReleaseMusicRename;
use App\Services\AudioProcessing\AudioEvidenceRecorder;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\TrackEvidence;

/**
 * Maps the durable evidence ledger into the resolver's immutable input. A revision's CUE sheet rows
 * (issue #313) stand in for the audio rows they describe: the resolver sees the `cue` rows plus
 * every audio row no CUE describes, a described row's identifiers carried onto the CUE track, and
 * the revision's rip-log disc IDs.
 */
final class AudioEvidenceSetFactory
{
    /** A described row's release-level identifiers, carried onto the first CUE track of its file. */
    private const array RELEASE_IDENTIFIERS = ['musicbrainz_release_id', 'musicbrainz_release_group_id', 'musicbrainz_artist_id', 'barcode', 'catalog_number', 'disc_id_like'];

    /** A described row's per-track identifiers, carried only when its file holds exactly one CUE track. */
    private const array TRACK_IDENTIFIERS = ['musicbrainz_recording_id', 'musicbrainz_track_id', 'isrc'];

    /** The sampled track's fingerprint travels with its per-track identifiers (#312 looks it up per track). */
    private const array FINGERPRINT = ['fingerprint', 'fingerprint_hash', 'fingerprint_algorithm', 'fingerprint_generator_version'];

    public function make(ReleaseAudioEvidence $evidence): AudioEvidenceSet
    {
        $evidence->loadMissing('tracks');
        $albumTrackEvidence = $evidence->tracks->first(
            fn (ReleaseAudioEvidenceTrack $trackEvidenceRecord): bool => $this->text($trackEvidenceRecord->getAttribute('album')) !== null,
        ) ?? $evidence->tracks->first();
        [$records, $overrides, $listComplete] = $this->resolverTracks($evidence);

        return new AudioEvidenceSet(
            evidenceId: $evidence->id,
            evidenceHash: $evidence->evidence_hash,
            releaseTitle: $this->releaseTitle($evidence),
            albumTitle: $albumTrackEvidence === null ? null : $this->text($albumTrackEvidence->getAttribute('album')),
            albumArtist: $albumTrackEvidence === null ? null : $this->text(
                $albumTrackEvidence->getAttribute('album_artist') ?? $albumTrackEvidence->getAttribute('performer'),
            ),
            releaseYear: $albumTrackEvidence === null ? null : $this->year($albumTrackEvidence->getAttribute('recorded_date')),
            trackEvidence: array_map(fn (ReleaseAudioEvidenceTrack $trackEvidenceRecord): TrackEvidence => $this->trackEvidence(
                $evidence,
                $trackEvidenceRecord,
                $overrides[$trackEvidenceRecord->id] ?? [],
            ), $records),
            trackEvidenceListComplete: $listComplete,
            albumProvenanceFamily: $albumTrackEvidence === null
                ? 'evidence:'.$evidence->id
                : $this->provenanceFamily($evidence, $albumTrackEvidence),
            mediumCount: $this->mediumCount($records, $listComplete),
            mediaFormat: $albumTrackEvidence === null ? null : $this->text($albumTrackEvidence->getAttribute('container')),
            ripLogDiscIds: $this->ripLogDiscIds($evidence),
        );
    }

    /**
     * The release name as captured with the evidence, the name the resolver may read an album from
     * (issue #1033). A name the music rename wrote from an accepted album is no evidence for that
     * album, so a snapshot holding one gives no name.
     */
    private function releaseTitle(ReleaseAudioEvidence $evidence): ?string
    {
        $snapshot = $evidence->release_snapshot;
        $searchName = $snapshot['searchname'] ?? null;
        if (is_string($searchName)) {
            foreach (ReleaseMusicRename::query()->where('releases_id', $evidence->releases_id)->get() as $rename) {
                if (($rename->after['searchname'] ?? null) === $searchName) {
                    return null;
                }
            }
        }

        return $this->text($searchName ?? $snapshot['name'] ?? null);
    }

    /**
     * The rows the resolver sees, the identifiers each CUE track takes from the row it describes,
     * and whether the list is complete. Without CUE rows: every row, and the archive listing's flag.
     *
     * @return array{0: list<ReleaseAudioEvidenceTrack>, 1: array<int, array<string, mixed>>, 2: bool|null}
     */
    private function resolverTracks(ReleaseAudioEvidence $evidence): array
    {
        $all = $evidence->tracks->values()->all();
        $cueRows = array_values(array_filter($all, static fn (ReleaseAudioEvidenceTrack $row): bool => $row->source_kind === AudioEvidenceRecorder::CUE_SOURCE_KIND));
        if ($cueRows === []) {
            return [$all, [], $evidence->archive_manifest_complete];
        }
        usort($cueRows, static fn (ReleaseAudioEvidenceTrack $left, ReleaseAudioEvidenceTrack $right): int => $left->source_ordinal <=> $right->source_ordinal);
        $audioRows = array_values(array_filter($all, static fn (ReleaseAudioEvidenceTrack $row): bool => in_array($row->source_kind, AudioEvidenceRecorder::AUDIO_SOURCE_KINDS, true)));
        $audioById = [];
        $audioArrays = [];
        foreach ($audioRows as $row) {
            $audioById[$row->id] = $row;
            $audioArrays[] = ['id' => $row->id, 'source_kind' => $row->source_kind, 'raw_filename' => $row->raw_filename];
        }

        [$sheets, $sheetsKnown] = $this->sheets($evidence, $cueRows);
        $described = [];
        $overrides = [];
        foreach ($sheets as $sheet) {
            $files = [];
            foreach ($sheet as $row) {
                $files[$row->raw_filename][] = $row;
            }
            foreach ($files as $name => $tracks) {
                $row = AudioEvidenceRecorder::describedRow((string) $name, count($files), $audioArrays);
                if ($row === null) {
                    continue;
                }
                $source = $audioById[(int) $row['id']];
                $described[$source->id] = true;
                $carried = self::RELEASE_IDENTIFIERS;
                if (count($tracks) === 1) {
                    $carried = [...$carried, ...self::TRACK_IDENTIFIERS];
                }
                foreach ($carried as $attribute) {
                    if ($this->text($tracks[0]->getAttribute($attribute)) === null && $this->text($source->getAttribute($attribute)) !== null) {
                        $overrides[$tracks[0]->id][$attribute] = $source->getAttribute($attribute);
                    }
                }
                if (count($tracks) === 1 && $this->text($tracks[0]->getAttribute('fingerprint')) === null && $this->text($source->getAttribute('fingerprint')) !== null) {
                    foreach (self::FINGERPRINT as $attribute) {
                        $overrides[$tracks[0]->id][$attribute] = $source->getAttribute($attribute);
                    }
                }
            }
        }

        $records = array_values(array_filter($all, static fn (ReleaseAudioEvidenceTrack $row): bool => ! isset($described[$row->id])));
        $everyAudioRowDescribed = count($described) === count($audioRows);
        $discsKnown = ($sheetsKnown && count($sheets) === 1) || array_filter($cueRows, static fn (ReleaseAudioEvidenceTrack $row): bool => $row->disc_number === null) === [];
        $complete = $everyAudioRowDescribed && $evidence->archive_manifest_complete !== false && $discsKnown
            ? true
            : $evidence->archive_manifest_complete;

        return [$records, $overrides, $complete];
    }

    /**
     * The CUE rows split by the sheet they came from: each read sheet's manifest entry counts its
     * tracks (`facts.cue_tracks`), in the order the rows were written.
     *
     * When the counts do not cover the rows, every row is taken as one group of unknown sheets,
     * which never counts as "exactly one sheet".
     *
     * @param  list<ReleaseAudioEvidenceTrack>  $cueRows  in CUE order
     * @return array{0: list<list<ReleaseAudioEvidenceTrack>>, 1: bool} the sheets, and whether the counts gave them
     */
    private function sheets(ReleaseAudioEvidence $evidence, array $cueRows): array
    {
        $sheets = [];
        $offset = 0;
        foreach ($evidence->sidecar_manifest ?? [] as $entry) {
            $count = is_array($entry) ? ($entry['facts']['cue_tracks'] ?? null) : null;
            if (! is_int($count) || $count < 1) {
                continue;
            }
            $sheets[] = array_slice($cueRows, $offset, $count);
            $offset += $count;
        }
        if ($sheets === [] || $offset !== count($cueRows)) {
            return [[$cueRows], false];
        }

        return [$sheets, true];
    }

    /**
     * The revision's rip-log disc IDs, each with its sidecar's provenance family.
     *
     * @return list<array{discId: string, provenanceFamily: string}>
     */
    private function ripLogDiscIds(ReleaseAudioEvidence $evidence): array
    {
        $discIds = [];
        foreach ($evidence->sidecar_manifest ?? [] as $entry) {
            if (! is_array($entry) || ! is_array($entry['facts']['disc_ids'] ?? null)) {
                continue;
            }
            foreach ($entry['facts']['disc_ids'] as $discId) {
                if (is_string($discId) && $discId !== '') {
                    $discIds[] = [
                        'discId' => $discId,
                        'provenanceFamily' => implode(':', ['evidence', (string) $evidence->id, 'sidecar', (string) ($entry['source'] ?? ''), (string) ($entry['ordinal'] ?? '')]),
                    ];
                }
            }
        }

        return $discIds;
    }

    /** @param array<string, mixed> $overrides */
    private function trackEvidence(
        ReleaseAudioEvidence $evidence,
        ReleaseAudioEvidenceTrack $trackEvidenceRecord,
        array $overrides = [],
    ): TrackEvidence {
        $attribute = static fn (string $name): mixed => array_key_exists($name, $overrides) ? $overrides[$name] : $trackEvidenceRecord->getAttribute($name);
        $duration = $trackEvidenceRecord->whole_duration_reliable === true
            ? $trackEvidenceRecord->whole_duration_seconds
            : null;
        $algorithm = $attribute('fingerprint_algorithm');

        return new TrackEvidence(
            evidenceTrackId: $trackEvidenceRecord->id,
            sourceKind: $trackEvidenceRecord->source_kind,
            sourceOrdinal: $trackEvidenceRecord->source_ordinal,
            rawFilename: $trackEvidenceRecord->raw_filename,
            title: $this->text($trackEvidenceRecord->getAttribute('title') ?? $trackEvidenceRecord->getAttribute('normalized_title_hint')),
            artist: $this->text(
                $trackEvidenceRecord->getAttribute('performer')
                    ?? $trackEvidenceRecord->getAttribute('album_artist')
                    ?? $trackEvidenceRecord->getAttribute('normalized_artist_hint'),
            ),
            durationMs: $duration === null ? null : (int) round($duration * 1000),
            recordingId: $this->text($attribute('musicbrainz_recording_id')),
            releaseId: $this->text($attribute('musicbrainz_release_id')),
            releaseGroupId: $this->text($attribute('musicbrainz_release_group_id')),
            musicBrainzReleaseTrackId: $this->text($attribute('musicbrainz_track_id')),
            artistId: $this->text($attribute('musicbrainz_artist_id')),
            isrc: $this->text($attribute('isrc')),
            discId: $this->text($attribute('disc_id_like')),
            barcode: $this->text($attribute('barcode')),
            catalogNumber: $this->text($attribute('catalog_number')),
            provenanceFamily: $this->provenanceFamily($evidence, $trackEvidenceRecord),
            discNumber: $trackEvidenceRecord->disc_number,
            releaseTrackNumber: $trackEvidenceRecord->track_number,
            fingerprint: $this->text($attribute('fingerprint')),
            fingerprintHash: $this->text($attribute('fingerprint_hash')),
            fingerprintAlgorithm: $algorithm === null ? null : (int) $algorithm,
            fingerprintGeneratorVersion: $this->text($attribute('fingerprint_generator_version')),
        );
    }

    /** @param list<ReleaseAudioEvidenceTrack> $records */
    private function mediumCount(array $records, ?bool $listComplete): ?int
    {
        if ($listComplete !== true) {
            return null;
        }

        $discNumbers = array_filter(array_map(
            static fn (ReleaseAudioEvidenceTrack $record): int => (int) $record->disc_number,
            $records,
        ));
        if ($discNumbers !== []) {
            return max($discNumbers);
        }

        return $records === [] ? null : 1;
    }

    private function provenanceFamily(
        ReleaseAudioEvidence $evidence,
        ReleaseAudioEvidenceTrack $trackEvidenceRecord,
    ): string {
        return implode(':', [
            'evidence',
            (string) $evidence->id,
            $trackEvidenceRecord->source_kind,
            (string) $trackEvidenceRecord->source_ordinal,
        ]);
    }

    private function year(mixed $value): ?int
    {
        $text = $this->text($value);
        if ($text === null || preg_match('/\b(?:19|20)\d{2}\b/', $text, $match) !== 1) {
            return null;
        }

        return (int) $match[0];
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
