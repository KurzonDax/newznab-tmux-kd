<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

/** A release-group search by the artist and album title read from a release name (issue #1033). */
final readonly class ReleaseGroupQuery
{
    public function __construct(
        public string $artist,
        public string $title,
        public ?int $limit = null,
    ) {}
}
