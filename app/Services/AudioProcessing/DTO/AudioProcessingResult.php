<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\DTO;

use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;

/**
 * What the audio path did with one release.
 */
final readonly class AudioProcessingResult
{
    public function __construct(
        public int $releaseId,
        public string $guid,
        public ProcessingOutcome $outcome,
        public bool $previewCreated = false,
        public bool $tagsRecorded = false,
        public string $reason = '',
    ) {}
}
