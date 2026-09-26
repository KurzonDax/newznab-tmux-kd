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
    ) {}

    public function titleUrl(): ?string
    {
        return match (true) {
            $this->root === 'tv' => route('tv.show', ['videosId' => $this->id]),
            in_array($this->root, ['movies', 'audio', 'console', 'games', 'books'], true) => route('title', ['root' => $this->root, 'id' => $this->id]),
            default => null,
        };
    }
}
