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
}
