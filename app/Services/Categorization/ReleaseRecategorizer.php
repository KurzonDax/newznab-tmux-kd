<?php

declare(strict_types=1);

namespace App\Services\Categorization;

use App\Facades\Search;
use App\Models\Release;
use App\Services\Releases\PreviewGenerationPolicy;

/**
 * Moves stored releases to the category the categorization pipeline gives them now.
 * nntmux:recategorize-releases and data migrations that retire a category share it.
 */
class ReleaseRecategorizer
{
    // Built on first use, so a refile with nothing to move never builds the pipeline.
    private ?CategorizationService $categorization = null;

    private ?PreviewGenerationPolicy $previewPolicy = null;

    /** The category the pipeline files the release under today. */
    public function categoryFor(Release $release): int
    {
        $this->categorization ??= new CategorizationService;

        return (int) $this->categorization->determineCategory(
            $release->groups_id,
            $release->searchname,
            $release->fromname,
            releaseId: (int) $release->id,
        )['categories_id'];
    }

    /**
     * Files the release under the category, clears the metadata links the old category
     * owned, queues a preview the old category's policy skipped and re-indexes it.
     */
    public function moveTo(int $releaseId, int $categoryId): void
    {
        Release::query()->where('id', $releaseId)->update([
            'iscategorized' => 1,
            'videos_id' => 0,
            'tv_episodes_id' => 0,
            'imdbid' => null,
            'musicinfo_id' => null,
            'consoleinfo_id' => null,
            'gamesinfo_id' => 0,
            'bookinfo_id' => null,
            'anidbid' => null,
            'categories_id' => $categoryId,
        ]);

        $this->previewPolicy ??= new PreviewGenerationPolicy;
        $this->previewPolicy->restoreOwedPreviews([$releaseId]);
        Search::updateRelease($releaseId);
    }

    /**
     * Moves every release stored in the category whose pipeline category differs.
     *
     * @return int How many releases moved.
     */
    public function refileCategory(int $categoryId): int
    {
        $moved = 0;
        Release::query()
            ->where('categories_id', $categoryId)
            ->select(['id', 'searchname', 'fromname', 'groups_id', 'categories_id'])
            ->eachById(function (Release $release) use ($categoryId, &$moved): void {
                $newCategoryId = $this->categoryFor($release);
                if ($newCategoryId !== $categoryId) {
                    $this->moveTo((int) $release->id, $newCategoryId);
                    $moved++;
                }
            }, 1000);

        return $moved;
    }
}
