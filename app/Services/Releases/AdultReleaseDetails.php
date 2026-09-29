<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\AdultReleaseRow;
use App\Models\Category;
use App\Models\Release;
use App\Models\ReleaseVideoClip;

/**
 * The Adult release details page (docs/proposals/adult-redesign/SPEC.md 5A): the release as an
 * Adult row, its sub-category for the breadcrumb, the clip's seconds for the preview's tag, the
 * facts grid, the PreDB block and Similar releases (today's ReleaseSearchService::searchSimilar(),
 * DATA-CONTRACT 4.4). No new storage is read.
 */
final class AdultReleaseDetails
{
    public function __construct(
        private readonly AdultReleaseRows $rows,
        private readonly ReleaseSearchService $search,
    ) {}

    /**
     * @param  list<int>  $exclusions
     * @return array<string, mixed>
     */
    public function forRelease(Release $release, string $category, array $exclusions): array
    {
        $row = $this->rows->load([(int) $release->id], false)[0] ?? null;
        abort_if($row === null, 404);

        return [
            'row' => $row,
            'category' => $category,
            'subCategory' => (string) (Category::query()->whereKey((int) $release->categories_id)->value('title') ?? $category),
            'clipSeconds' => $row->clip === null ? null : $this->clipSeconds((int) $release->id),
            'facts' => ReleaseDetailsFacts::grid($release, $row, $category),
            'predb' => ReleaseDetailsFacts::predb((int) $release->predb_id),
            'similar' => $this->similar($release, $exclusions),
        ];
    }

    /**
     * The clip's length for the preview's "Clip · N s" tag (DATA-CONTRACT 4.3): its
     * release_video_clips row's seconds, null with no row or no value.
     */
    private function clipSeconds(int $releaseId): ?int
    {
        $seconds = ReleaseVideoClip::query()->where('releases_id', $releaseId)->value('duration_seconds');

        return $seconds === null || (int) $seconds <= 0 ? null : (int) $seconds;
    }

    /**
     * Similar releases: today's search (the first two words of the name in the Adult categories,
     * the viewer's excluded categories and password setting applied, without this release).
     *
     * @param  list<int>  $exclusions
     * @return list<AdultReleaseRow>
     */
    private function similar(Release $release, array $exclusions): array
    {
        $found = $this->search->searchSimilar((int) $release->id, (string) $release->searchname, $exclusions);
        if (! is_iterable($found)) {
            return [];
        }
        $ids = [];
        foreach ($found as $match) {
            $ids[] = (int) $match['id'];
        }

        return $ids === [] ? [] : $this->rows->load($ids, false);
    }
}
