<?php

declare(strict_types=1);

namespace App\Services\MediaInfo\DTO;

use App\Services\MediaInfo\Enums\MediaInfoSourceCompleteness;
use App\Services\MediaInfo\Enums\MediaInfoSourceKind;
use Illuminate\Support\Carbon;

final readonly class MediaInfoProbeContext
{
    public function __construct(
        public MediaInfoSourceKind $sourceKind,
        public ?string $sourceFilename,
        public MediaInfoSourceCompleteness $sourceCompleteness,
        public ?Carbon $capturedAt = null,
    ) {}
}
