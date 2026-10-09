<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Enums;

/**
 * The MusicBrainz artist alias types an accepted album's artists are searched by (issue #313):
 * "Artist name" and "Search hint". Legal names and untyped aliases are left out.
 */
enum ArtistAliasType: string
{
    case ArtistName = 'artist_name';
    case SearchHint = 'search_hint';

    /** The type MusicBrainz names; null for any type that is not searched. */
    public static function fromMusicBrainz(?string $type): ?self
    {
        return match ($type) {
            'Artist name' => self::ArtistName,
            'Search hint' => self::SearchHint,
            default => null,
        };
    }
}
