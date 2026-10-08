<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\CoverArt;

/** The answer to one Cover Art Archive front request (CoverArtArchiveClient). */
final readonly class CoverArtFront
{
    private function __construct(
        public CoverArtOutcome $outcome,
        public string $bytes = '',
        public ?string $error = null,
    ) {}

    public static function image(string $bytes): self
    {
        return new self(CoverArtOutcome::Stored, $bytes);
    }

    public static function none(): self
    {
        return new self(CoverArtOutcome::NoFrontImage);
    }

    public static function failed(string $error): self
    {
        return new self(CoverArtOutcome::Failed, error: $error);
    }
}
