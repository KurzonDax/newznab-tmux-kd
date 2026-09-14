<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ReleaseCoverItem
{
    /**
     * @param  list<string>  $metadata
     * @param  list<object>  $releases
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $artwork,
        public string $identifyingLine,
        public int $releaseCount,
        public string $artworkTag = '',
        public string $footerBadge = '',
        public string $footerValue = '',
        public ?string $year = null,
        public array $metadata = [],
        public array $releases = [],
        public string $titleUrl = '',
        public ?string $watchUrl = null,
        public bool $watched = false,
        public ?string $watchId = null,
        public string $genres = '',
    ) {}
}
