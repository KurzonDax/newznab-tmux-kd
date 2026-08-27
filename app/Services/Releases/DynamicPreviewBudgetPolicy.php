<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Category;
use App\Models\RootCategory;

/**
 * Per-root-category Dynamic segment budget policy.
 *
 * The dynamic segment budget (see CONTEXT.md) sizes the fetched head of a
 * release's main video file by target duration instead of a fixed segment
 * count. It is opt-in per root category and only the Movies, TV, and XXX
 * roots are eligible; every other root — and any eligible root with the
 * toggle off — keeps the fixed-count behavior exactly. Unlike
 * {@see PreviewGenerationPolicy} (which can only disable work), this toggle
 * defaults to off: unknown or rootless categories never get the budget.
 */
class DynamicPreviewBudgetPolicy
{
    /**
     * Root category ids the budget may be enabled for.
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

            foreach (RootCategory::query()->pluck('dynamic_preview_budget', 'id') as $id => $enabled) {
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
