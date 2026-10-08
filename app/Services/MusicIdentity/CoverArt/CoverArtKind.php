<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\CoverArt;

/** The MusicBrainz entity a Cover Art Archive lookup asks for the front image of; its value is the URL segment. */
enum CoverArtKind: string
{
    case Release = 'release';
    case ReleaseGroup = 'release-group';
}
