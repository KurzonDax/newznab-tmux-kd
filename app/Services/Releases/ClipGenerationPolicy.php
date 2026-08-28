<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Category;
use App\Models\RootCategory;

/**
 * Per-root-category Clip policy (see CONTEXT.md).
 *
 * When enabled for a release's root and the source streams are browser-safe,
 * the video artifact is a full-resolution stream-copy Clip; otherwise no
 * video artifact is stored (Clip-or-nothing). Only the Movies, TV, and XXX
 * roots are eligible, and the toggle defaults to off: unknown or rootless
 * categories never store a Clip. Follows {@see DynamicPreviewBudgetPolicy}.
 */
class ClipGenerationPolicy
{
    /**
     * Root category ids Clips may be enabled for.
     */
    public const array ELIGIBLE_ROOT_IDS = [
        Category::MOVIE_ROOT,
        Category::TV_ROOT,
        Category::XXX_ROOT,
    ];

    /**
     * @var array<int, bool>|null
     */
    private ?array $rootToggles = null;

    /**
     * @var array<int, int|null>
     */
    private array $categoryRootMap = [];

    public function enabledForCategory(int $categoriesId): bool
    {
        $rootCategoryId = $this->rootCategoryIdFor($categoriesId);

        return $rootCategoryId !== null
            && in_array($rootCategoryId, self::ELIGIBLE_ROOT_IDS, true)
            && ($this->rootToggles()[$rootCategoryId] ?? false);
    }

    /**
     * @return array<int, bool>
     */
    private function rootToggles(): array
    {
        if ($this->rootToggles === null) {
            $toggles = [];

            foreach (RootCategory::query()->pluck('generate_clips', 'id') as $id => $enabled) {
                $toggles[(int) $id] = (bool) $enabled;
            }

            $this->rootToggles = $toggles;
        }

        return $this->rootToggles;
    }

    private function rootCategoryIdFor(int $categoriesId): ?int
    {
        if (! \array_key_exists($categoriesId, $this->categoryRootMap)) {
            $rootCategoryId = Category::query()->where('id', $categoriesId)->value('root_categories_id');
            $this->categoryRootMap[$categoriesId] = $rootCategoryId === null ? null : (int) $rootCategoryId;
        }

        return $this->categoryRootMap[$categoriesId];
    }
}
