<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

final readonly class UnknownPayloadCandidate
{
    /** @param list<int> $segmentNumbers */
    public function __construct(
        public string $title,
        public string $firstMessageId,
        public int $segmentCount,
        public int $estimatedSizeBytes,
        public int $estimatedFirstSegmentBytes,
        public int $sourceIndex,
        public array $segmentNumbers = [],
        public int $declaredSegments = 0,
        public int $nzbFileIndex = -1,
        public string $fingerprint = '',

    ) {}
}
