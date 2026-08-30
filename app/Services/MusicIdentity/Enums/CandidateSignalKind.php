<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Enums;

enum CandidateSignalKind: string
{
    case Barcode = 'barcode';
    case CatalogNumber = 'catalog_number';
    case DiscId = 'disc_id';
    case DiscToc = 'disc_toc';
    case EmbeddedRecordingId = 'embedded_recording_id';
    case EmbeddedReleaseGroupId = 'embedded_release_group_id';
    case EmbeddedReleaseId = 'embedded_release_id';
    case EmbeddedReleaseTrackId = 'embedded_release_track_id';
    case Isrc = 'isrc';
    case ReleaseSearch = 'release_search';
    case TrackEvidenceSearch = 'track_evidence_search';
}
