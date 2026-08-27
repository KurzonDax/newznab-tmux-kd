<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

final readonly class VideoHeadProbeResult
{
    public function __construct(
        public ?float $durationSeconds = null,
        public ?int $bitsPerSecond = null,
    ) {}

    /**
     * Overall bytes per second for the whole posted file, preferring the
     * container-declared duration against the NZB's full file size (accurate
     * for a partial download) over the probe's reported bitrate (computed
     * from the partial file's size, so it underestimates).
     */
    public function bytesPerSecond(int $totalFileSizeBytes): ?float
    {
        if ($this->durationSeconds !== null && $this->durationSeconds > 0 && $totalFileSizeBytes > 0) {
            return $totalFileSizeBytes / $this->durationSeconds;
        }

        if ($this->bitsPerSecond !== null && $this->bitsPerSecond > 0) {
            return $this->bitsPerSecond / 8;
        }

        return null;
    }
}
