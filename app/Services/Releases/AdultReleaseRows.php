<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\AdultReleaseRow;
use App\Models\ReleaseVideoClip;
use Carbon\CarbonImmutable;

/**
 * Loads a page of Adult release ids into display rows: ReleaseRowFacts supplies the facts every
 * release list shows; this adds the video clip (docs/proposals/adult-redesign/DATA-CONTRACT.md
 * 4.3). The shared loader (ReleasePreviewDataLoader) already marks a release with
 * `videostatus = 1` as having a video preview, with the type today's player serves; the list's
 * Clip chip reads "Clip" (SPEC 5.10), so no further read is needed.
 */
final class AdultReleaseRows
{
    public function __construct(private readonly ReleaseRowFacts $facts) {}

    /**
     * @param  list<int>  $ids  in display order
     * @return list<AdultReleaseRow>
     */
    public function load(array $ids, bool $byAdded): array
    {
        $now = CarbonImmutable::now(config('app.timezone', 'UTC'));

        return array_map(function (object $release) use ($byAdded, $now): AdultReleaseRow {
            $facts = $this->facts->facts($release, $byAdded, $now);
            $clip = (bool) ($release->has_video_preview ?? false) ? [
                'url' => route('preview.video', $facts['guid']),
                'type' => (string) ($release->video_preview_mime ?? ReleaseVideoClip::VIDEO_MIME_TYPES['ogv']),
            ] : null;

            return new AdultReleaseRow(...[...$facts, 'clip' => $clip]);
        }, $this->facts->load($ids));
    }
}
