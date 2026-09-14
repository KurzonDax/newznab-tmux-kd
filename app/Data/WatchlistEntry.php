<?php

declare(strict_types=1);

namespace App\Data;

final readonly class WatchlistEntry
{
    /** @param list<string> $categories */
    public function __construct(
        public string $id,
        public string $root,
        public string $title,
        public bool $watched,
        public string $year,
        public string $network,
        public ?object $latest,
        public ?string $artwork,
        public string $url,
        public array $categories,
    ) {}
}
