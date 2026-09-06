<?php

declare(strict_types=1);

namespace App\Services\MediaInfo\DTO;

final readonly class MediaInfoSnapshotData
{
    /**
     * @param  array<string, mixed>  $container
     * @param  list<array<string, mixed>>  $streams
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public array $container,
        public array $streams,
        public array $provenance,
    ) {}
}
