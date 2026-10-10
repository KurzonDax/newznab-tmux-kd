<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

/** The one MusicBrainz release group a release name identifies, and the reading of the name that found it. */
final readonly class ReleaseNameAlbumMatch
{
    /**
     * @param  string  $title  the reading's title as named, before any variant
     * @param  'unique'|'year'|'album_type'  $rule  what singled the group out
     * @param  list<string>  $responseCacheKeys  those of the searches made for the matching reading
     */
    public function __construct(
        public string $releaseGroupId,
        public string $artist,
        public string $title,
        public ?int $year,
        public string $rule,
        public array $responseCacheKeys = [],
    ) {}
}
