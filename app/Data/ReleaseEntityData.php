<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ReleaseEntityData
{
    public function __construct(
        public string $root,
        public string $id,
        public string $title,
        public ?string $year,
        public ?string $artwork,
        public ?int $season = null,
        public ?int $episode = null,
        public ?int $filmId = null,
    ) {}

    public function titleUrl(): ?string
    {
        return match (true) {
            $this->root === 'tv' => route('tv.show', ['videosId' => $this->id]),
            $this->root === 'movies' => $this->filmId === null ? null : route('movies.film', ['movieinfoId' => $this->filmId]),
            in_array($this->root, ['audio', 'console', 'games', 'books'], true) => route('title', ['root' => $this->root, 'id' => $this->id]),
            default => null,
        };
    }
}
