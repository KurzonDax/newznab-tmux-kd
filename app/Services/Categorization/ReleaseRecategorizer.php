<?php

declare(strict_types=1);

namespace App\Services\Categorization;

use App\Models\Release;

/**
 * Moves stored releases to the category the categorization pipeline gives them now.
 * nntmux:recategorize-releases and data migrations that retire a category share it.
 */
class ReleaseRecategorizer
{
    // Built on first use, so a refile with nothing to move never builds the pipeline.
    private ?CategorizationService $categorization = null;

    private ?CategoryChange $categoryChange = null;

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
     * owned, queues a preview the old category's policy skipped and re-indexes it. Returns
     * whether the release was still in the category it was read from.
     */
    public function moveTo(int $releaseId, int $fromCategoryId, int $toCategoryId): bool
    {
        $this->categoryChange ??= new CategoryChange;

        return $this->categoryChange->apply($releaseId, $fromCategoryId, $toCategoryId, CategoryChangeKind::Recategorize);
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
                if ($newCategoryId !== $categoryId && $this->moveTo((int) $release->id, $categoryId, $newCategoryId)) {
                    $moved++;
                }
            }, 1000);

        return $moved;
    }
}
