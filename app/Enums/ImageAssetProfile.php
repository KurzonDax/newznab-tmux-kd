<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Image bounds used by the existing cover and sample producers.
 */
enum ImageAssetProfile
{
    case Original;
    case MetadataCover;
    case Backdrop;
    case Preview;
    case Sample;

    /**
     * The Full-size copy stored beside a release's display thumb: fitted to a
     * desktop-viewport box, never upscaled, always re-encoded (see ADR 0012).
     */
    case FullSize;

    public function maxWidth(): ?int
    {
        return match ($this) {
            self::Original => null,
            self::MetadataCover => 250,
            self::Backdrop => 1920,
            self::Preview => 800,
            self::Sample => 650,
            self::FullSize => max(1, (int) config('image.full_max_width', 1920)),
        };
    }

    public function maxHeight(): ?int
    {
        return match ($this) {
            self::Original => null,
            self::MetadataCover => 250,
            self::Backdrop => 1024,
            self::Preview => 600,
            self::Sample => 650,
            self::FullSize => max(1, (int) config('image.full_max_height', 1920)),
        };
    }

    /**
     * Encoder quality for this profile, or null to use the site-wide default.
     */
    public function quality(): ?int
    {
        return match ($this) {
            self::FullSize => max(1, min(100, (int) config('image.full_output_quality', 90))),
            default => null,
        };
    }
}
