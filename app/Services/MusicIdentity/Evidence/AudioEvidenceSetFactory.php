<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Evidence;

use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseAudioEvidenceTrack;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\TrackEvidence;

/**
 * Maps the durable evidence ledger into the resolver's immutable input.
 */
final class AudioEvidenceSetFactory
{
    public function make(ReleaseAudioEvidence $evidence): AudioEvidenceSet
    {
        $evidence->loadMissing('tracks');
        $albumTrackEvidence = $evidence->tracks->first(
            fn (ReleaseAudioEvidenceTrack $trackEvidenceRecord): bool => $this->text($trackEvidenceRecord->getAttribute('album')) !== null,
        ) ?? $evidence->tracks->first();
        $snapshot = $evidence->release_snapshot;

        return new AudioEvidenceSet(
            evidenceId: $evidence->id,
            evidenceHash: $evidence->evidence_hash,
            releaseTitle: $this->text($snapshot['searchname'] ?? $snapshot['name'] ?? null),
            albumTitle: $albumTrackEvidence === null ? null : $this->text($albumTrackEvidence->getAttribute('album')),
            albumArtist: $albumTrackEvidence === null ? null : $this->text(
                $albumTrackEvidence->getAttribute('album_artist') ?? $albumTrackEvidence->getAttribute('performer'),
            ),
            releaseYear: $albumTrackEvidence === null ? null : $this->year($albumTrackEvidence->getAttribute('recorded_date')),
            trackEvidence: $evidence->tracks
                ->map(fn (ReleaseAudioEvidenceTrack $trackEvidenceRecord): TrackEvidence => $this->trackEvidence(
                    $evidence,
                    $trackEvidenceRecord,
                ))
                ->values()
                ->all(),
            trackEvidenceListComplete: $evidence->archive_manifest_complete,
            albumProvenanceFamily: $albumTrackEvidence === null
                ? 'evidence:'.$evidence->id
                : $this->provenanceFamily($evidence, $albumTrackEvidence),
            mediumCount: $this->mediumCount($evidence),
            mediaFormat: $albumTrackEvidence === null ? null : $this->text($albumTrackEvidence->getAttribute('container')),
        );
    }

    private function trackEvidence(
        ReleaseAudioEvidence $evidence,
        ReleaseAudioEvidenceTrack $trackEvidenceRecord,
    ): TrackEvidence {
        $duration = $trackEvidenceRecord->whole_duration_reliable === true
            ? $trackEvidenceRecord->whole_duration_seconds
            : null;

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
            recordingId: $this->text($trackEvidenceRecord->getAttribute('musicbrainz_recording_id')),
            releaseId: $this->text($trackEvidenceRecord->getAttribute('musicbrainz_release_id')),
            releaseGroupId: $this->text($trackEvidenceRecord->getAttribute('musicbrainz_release_group_id')),
            musicBrainzReleaseTrackId: $this->text($trackEvidenceRecord->getAttribute('musicbrainz_track_id')),
            artistId: $this->text($trackEvidenceRecord->getAttribute('musicbrainz_artist_id')),
            isrc: $this->text($trackEvidenceRecord->getAttribute('isrc')),
            discId: $this->text($trackEvidenceRecord->getAttribute('disc_id_like')),
            barcode: $this->text($trackEvidenceRecord->getAttribute('barcode')),
            catalogNumber: $this->text($trackEvidenceRecord->getAttribute('catalog_number')),
            provenanceFamily: $this->provenanceFamily($evidence, $trackEvidenceRecord),
            discNumber: $trackEvidenceRecord->disc_number,
            releaseTrackNumber: $trackEvidenceRecord->track_number,
        );
    }

    private function mediumCount(ReleaseAudioEvidence $evidence): ?int
    {
        if ($evidence->archive_manifest_complete !== true) {
            return null;
        }

        $discNumbers = $evidence->tracks->pluck('disc_number')->filter()->map(
            static fn (mixed $discNumber): int => (int) $discNumber,
        );
        if ($discNumbers->isNotEmpty()) {
            return $discNumbers->max();
        }

        return $evidence->tracks->isEmpty() ? null : 1;
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
