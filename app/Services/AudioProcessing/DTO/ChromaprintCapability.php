<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\DTO;

/**
 * Whether the configured FFmpeg build can write Chromaprint fingerprints.
 */
final readonly class ChromaprintCapability
{
    private function __construct(
        public bool $available,
        public ?string $ffmpegVersion,
        public string $reason,
    ) {}

    public static function available(?string $ffmpegVersion): self
    {
        return new self(
            true,
            $ffmpegVersion,
            'FFmpeg '.($ffmpegVersion ?? '(unknown version)').' provides the Chromaprint muxer.',
        );
    }

    public static function unavailable(string $reason, ?string $ffmpegVersion = null): self
    {
        return new self(false, $ffmpegVersion, $reason);
    }
}
