<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\CoverArt;

/** What one Cover Art Archive lookup found (music_cover_art_lookups.outcome). */
enum CoverArtOutcome: string
{
    /** The front image is stored, named after image_musicbrainz_id. */
    case Stored = 'stored';

    /** No front image (HTTP 404); never checked again. */
    case NoFrontImage = 'no_front_image';

    /** Any other answer or a transport error; retried with backoff at next_attempt_at. */
    case Failed = 'failed';
}
