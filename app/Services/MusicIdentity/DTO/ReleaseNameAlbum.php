<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

/** One reading of a release name as an album: who made it, what it may be called, and its year. */
final readonly class ReleaseNameAlbum
{
    /** @param non-empty-list<string> $titles the title as named, then its variants, most specific first */
    public function __construct(
        public string $artist,
        public array $titles,
        public ?int $year,
    ) {}
}
