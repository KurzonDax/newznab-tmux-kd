<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

final readonly class VideoClipEncodeResult
{
    public function __construct(
        public string $path,
        public string $extension,
        public string $mime,
        public ?int $durationSeconds,
        public int $bytes,
    ) {}
}
