<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an audio preview clip was produced from its source file.
 *
 * The distinction is worth surfacing because it says what the clip proves: a
 * stream copy is a verbatim slice of the posted audio, so its bitrate and codec
 * are the source's and can be judged; a transcode only shows that the source
 * decoded.
 */
enum AudioPreviewEncoding: string
{
    /** Copied verbatim: the clip carries the source's own codec and bitrate. */
    case StreamCopy = 'stream-copy';

    /** Re-encoded to FLAC because no browser plays the source container. */
    case FlacTranscode = 'flac-transcode';

    public function label(): string
    {
        return match ($this) {
            self::StreamCopy => 'stream copy',
            self::FlacTranscode => 'FLAC transcode',
        };
    }
}
