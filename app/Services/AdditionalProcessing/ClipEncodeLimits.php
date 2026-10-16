<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

/**
 * Bounds on every Clip encode that decodes video, so a very large source
 * cannot make ffmpeg's memory grow with the frame size or the core count.
 */
final class ClipEncodeLimits
{
    /**
     * Fit the picture inside 1920x1080, keeping the aspect ratio, never
     * upscaling, and keeping both sides even. The quotes reach ffmpeg's filter
     * parser (there is no shell) and protect the commas inside min().
     */
    public const string SCALE_FILTER = "scale=w='min(1920,iw)':h='min(1080,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2";

    /**
     * Thread count for the decoder (before -i) and the encoder (after -i).
     */
    public const int THREADS = 4;
}
