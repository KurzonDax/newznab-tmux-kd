<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\DTO;

/**
 * What the encoder produced, ready to be written onto the release's tag row.
 */
final readonly class AudioPreviewResult
{
    public function __construct(
        public string $extension,
        public string $mimeType,
        public int $seconds,
        public int $bytes,
        public bool $streamCopied,
        public bool $spectrogram = false,
    ) {}

    public function withSpectrogram(bool $spectrogram): self
    {
        return new self(
            $this->extension,
            $this->mimeType,
            $this->seconds,
            $this->bytes,
            $this->streamCopied,
            $spectrogram,
        );
    }
}
