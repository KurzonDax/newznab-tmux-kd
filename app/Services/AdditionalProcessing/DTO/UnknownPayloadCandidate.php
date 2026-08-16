<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

final readonly class UnknownPayloadCandidate
{
    public function __construct(
        public string $title,
        public string $firstMessageId,
        public int $segmentCount,
        public int $estimatedSizeBytes,
        public int $estimatedFirstSegmentBytes,
        public int $sourceIndex,
    ) {}
}
