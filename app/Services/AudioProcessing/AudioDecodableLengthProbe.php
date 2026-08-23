<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Services\AdditionalProcessing\MediaTools;

/**
 * Measures the decodable duration in a possibly truncated audio extraction.
 *
 * Container duration metadata is unreliable for a broken RAR extraction. The
 * last packet ffprobe can demux tells the fetcher whether the requested preview
 * window is already available without downloading another archive volume.
 */
class AudioDecodableLengthProbe
{
    public function __construct(private readonly MediaTools $mediaTools) {}

    public function demuxedSeconds(string $path): float
    {
        try {
            $output = $this->mediaTools->ffprobe()->getFFProbeDriver()->command([
                '-v',
                'error',
                '-select_streams',
                'a:0',
                '-show_entries',
                'packet=pts_time',
                '-of',
                'csv=p=0',
                $path,
            ]);
        } catch (\Throwable) {
            return 0.0;
        }

        $lastTimestamp = 0.0;
        foreach (preg_split('/\R/', trim($output)) ?: [] as $timestamp) {
            $timestamp = trim($timestamp);
            if (is_numeric($timestamp)) {
                $lastTimestamp = (float) $timestamp;
            }
        }

        return max(0.0, $lastTimestamp);
    }
}
