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
        return in_array($this->root, ['movies', 'tv', 'audio', 'console', 'games', 'books'], true)
            ? route('title', ['root' => $this->root, 'id' => $this->id]) : null;
    }
}
