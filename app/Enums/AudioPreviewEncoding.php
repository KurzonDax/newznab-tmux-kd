<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an audio preview clip was produced from its source file.
 *
 * The distinction is worth surfacing because it says what the clip proves: a
 * stream copy is a verbatim slice of the posted audio, a lossless re-encode
 * preserves the posted samples while fixing the clip container, and a
 * transcode from another source format only shows that the source decoded.
 */
enum AudioPreviewEncoding: string
{
    /** Copied verbatim: the clip carries the source's own codec and bitrate. */
    case StreamCopy = 'stream-copy';

    /** Re-encoded from FLAC to give the clipped file accurate duration metadata. */
    case FlacReencode = 'flac-reencode';

    /** Re-encoded to FLAC because no browser plays the source container. */
    case FlacTranscode = 'flac-transcode';

    public function label(): string
    {
        return match ($this) {
            self::StreamCopy => 'stream copy',
            self::FlacReencode => 'lossless re-encode',
            self::FlacTranscode => 'FLAC transcode',
        };
    }
}
