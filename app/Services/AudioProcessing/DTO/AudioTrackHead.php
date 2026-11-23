<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\DTO;

use Mhor\MediaInfo\Container\MediaInfoContainer;

/**
 * The first article of another audio file posted as its own NZB file, as MediaInfo read it.
 */
final readonly class AudioTrackHead
{
    public function __construct(
        public MediaInfoContainer $mediaInfo,
        /** The file is one article, so the probe saw all of it. */
        public bool $wholeFile,
        /** The probe's length is the whole file's, not the length of the downloaded piece. */
        public bool $wholeLength,
    ) {}
}
