<?php

declare(strict_types=1);

namespace App\Services\Par2Sidecar;

final readonly class SidecarLinkDecision
{
    public function __construct(
        public ?string $filename = null,
        public ?int $sourceId = null,
        public bool $combine = false,
        public string $reason = 'no_match',
    ) {}
}
