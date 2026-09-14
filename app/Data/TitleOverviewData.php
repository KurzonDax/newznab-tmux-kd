<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\BrowseRoot;

final readonly class TitleOverviewData
{
    /**
     * @param  array<string, string>  $metadata
     * @param  array<string, string>  $links
     * @param  list<string>  $tracks
     */
    public function __construct(
        public BrowseRoot $root,
        public ReleaseEntityData $entity,
        public string $subtitle,
        public array $metadata,
        public array $links,
        public string $overview,
        public array $tracks,
        public ?string $trailerUrl = null,
    ) {}
}
