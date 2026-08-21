<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Models\Release;
use App\Models\UsenetGroup;
use App\Services\Binaries\BinariesService;

/**
 * Where in a group's numbering to look for a release's missing headers.
 *
 * Two routes, and which one applies is a property of the release's age rather than a choice:
 *
 * - **Anchored.** Releases created since `firstarticle`/`lastarticle` existed know exactly which
 *   article numbers their collection spanned. The window is that span plus a pad, because a file
 *   the scan missed entirely was posted near its siblings but not necessarily between them.
 * - **Bisected.** Legacy releases have only a postdate, so the group is bisected for the article
 *   numbers either side of it -- the same date-to-article search backfill uses, aimed at an
 *   absolute time. This costs a round trip per probe, which is why the anchors exist.
 *
 * The pad is expressed in posting *time* and converted to article numbers with the group's own
 * article rate, so half an hour on a quiet group is a few thousand articles and half an hour on a
 * busy one is a few million -- which is what the per-release article ceiling is there to catch.
 */
final class RescanWindowResolver
{
    public function __construct(private readonly BinariesService $binaries) {}

    /**
     * @param  array<string, mixed>  $groupNntp  The server's own summary: `first`, `last`, `group`.
     * @return RescanWindow|null Null when the release gives nothing to aim at -- no anchors and no
     *                           usable postdate -- which is a fact about our records, not the release.
     *
     * @throws \Exception
     */
    public function resolve(Release $release, UsenetGroup $group, array $groupNntp, int $windowMinutes): ?RescanWindow
    {
        $groupFirst = (int) ($groupNntp['first'] ?? 0);
        $groupLast = (int) ($groupNntp['last'] ?? 0);

        if ($groupLast <= 0 || $groupLast < $groupFirst) {
            return null;
        }

        $padSeconds = max(0, $windowMinutes) * 60;
        $anchoredFirst = (int) ($release->firstarticle ?? 0);
        $anchoredLast = (int) ($release->lastarticle ?? 0);

        if ($anchoredFirst > 0 && $anchoredLast >= $anchoredFirst) {
            $pad = (int) ceil($this->articlesPerSecond($group) * $padSeconds);

            return $this->clamp($anchoredFirst - $pad, $anchoredLast + $pad, $groupFirst, $groupLast, anchored: true);
        }

        $centre = $release->postdate === null ? false : strtotime((string) $release->postdate);

        if ($centre === false) {
            return null;
        }

        return $this->clamp(
            (int) $this->binaries->articleForTimestamp($centre - $padSeconds, $groupNntp),
            (int) $this->binaries->articleForTimestamp($centre + $padSeconds, $groupNntp),
            $groupFirst,
            $groupLast,
            anchored: false,
        );
    }

    /**
     * Articles the group carries per second, from its own scan pointers.
     *
     * Returns `0` when the pointers cannot support an estimate, which collapses the pad to nothing
     * and leaves the window exactly the span the collection is known to have occupied. That is the
     * cheap, conservative answer rather than a guessed one: it can miss a straggler, but it cannot
     * spend an unbounded number of overview lines on a group whose rate we do not know.
     */
    private function articlesPerSecond(UsenetGroup $group): float
    {
        $firstRecord = (int) $group->first_record;
        $lastRecord = (int) $group->last_record;

        if ($lastRecord <= $firstRecord) {
            return 0.0;
        }

        $firstAt = $group->first_record_postdate === null ? false : strtotime((string) $group->first_record_postdate);
        $lastAt = $group->last_record_postdate === null ? false : strtotime((string) $group->last_record_postdate);

        if ($firstAt === false || $lastAt === false || $lastAt <= $firstAt) {
            return 0.0;
        }

        return ($lastRecord - $firstRecord) / ($lastAt - $firstAt);
    }

    private function clamp(int $first, int $last, int $groupFirst, int $groupLast, bool $anchored): ?RescanWindow
    {
        $first = max($first, $groupFirst, 1);
        $last = min($last, $groupLast);

        return $last < $first ? null : new RescanWindow($first, $last, $anchored);
    }
}
