<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\AdultReleaseRow;
use Carbon\CarbonImmutable;

/**
 * Loads a page of Adult release ids into display rows, for the list and the Previews wall:
 * ReleaseRowFacts supplies the facts every release list shows; this adds the video clip
 * (docs/proposals/adult-redesign/DATA-CONTRACT.md 4.3 and 4.5). The shared loader
 * (ReleasePreviewDataLoader) already marks a release with `videostatus = 1` as having a video
 * preview, with the type today's player serves and the clip's seconds; ReleaseRowFacts::clip()
 * adds the poster. The list's Preview chip shows no length (SPEC 5.10); the wall's clip tag does (5B.4).
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

            return new AdultReleaseRow(...[...$facts, 'clip' => ReleaseRowFacts::clip($release, $facts['guid'])]);
        }, $this->facts->load($ids));
    }
}
