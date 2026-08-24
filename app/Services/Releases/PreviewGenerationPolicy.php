<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Category;
use App\Models\Release;
use App\Models\RootCategory;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Support\ReleaseSearchIndexSync;

/**
 * Per-root-category Preview Generation policy (ADR 0004).
 *
 * Preview Generation covers exactly the two ffmpeg-created artifacts — the
 * Generated Preview and the Generated Sample Video — plus the sample-article
 * downloads whose only purpose is to feed them. The per-root toggle is AND-ed
 * with the site-wide processthumbnails/processvideos switches: it can only
 * disable, never enable. A release skipped by the toggle records the
 * {@see self::HASPREVIEW_SKIPPED_BY_POLICY} sentinel, which stays invisible to
 * the additional-processing candidate query (selects -1) and to every display
 * surface (checks == 1).
 */
class PreviewGenerationPolicy
{
    /**
     * haspreview sentinel: preview generation was never attempted because the
     * release's root category has it disabled. Distinct from 0 (attempted,
     * nothing produced) — only this state is owed generation when the release
     * is recategorized into a root with generation enabled.
     */
    public const int HASPREVIEW_SKIPPED_BY_POLICY = -2;

    /**
     * @var array<int, bool>|null
     */
    private ?array $rootToggles = null;

    /**
     * @var array<int, int|null>
     */
    private array $categoryRootMap = [];

    /**
     * Is Preview Generation enabled for the root this leaf category rolls up
     * to? Unknown categories and categories without a root stay enabled —
     * the toggle can only ever disable work.
     */
    public function generationEnabledForCategory(int $categoriesId): bool
    {
        $rootCategoryId = $this->rootCategoryIdFor($categoriesId);

        return $rootCategoryId === null || ($this->rootToggles()[$rootCategoryId] ?? true);
    }

    /**
     * Leaf category ids under roots with generation disabled. Expressed as a
     * disabled-list so unknown or rootless categories default to enabled
     * everywhere, matching {@see generationEnabledForCategory()}.
     *
     * @return list<int>
     */
    public function categoryIdsWithGenerationDisabled(): array
    {
        $disabledRootIds = array_keys(array_filter(
            $this->rootToggles(),
            static fn (bool $enabled): bool => ! $enabled,
        ));

        if ($disabledRootIds === []) {
            return [];
        }

        return Category::query()
            ->whereIn('root_categories_id', $disabledRootIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Owed regeneration after a category change: flip releases carrying the
     * skipped-by-policy sentinel back to pending when their (new) category's
     * root has generation enabled, so the normal additional-processing cycle
     * regenerates them. Releases moved into a disabled root keep the sentinel.
     *
     * Call immediately after every post-creation categories_id write — those
     * writes are query-builder updates, so model events never fire (the same
     * reason each write site hand-calls search-index sync). The flipped
     * releases are re-synced to the search index here.
     *
     * @param  iterable<int|string>  $releaseIds
     * @return int Number of releases flipped back to pending.
     */
    public function restoreOwedPreviews(iterable $releaseIds, bool $synchronize = true): int
    {
        $ids = [];
        foreach ($releaseIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[] = $intId;
            }
        }

        if ($ids === []) {
            return 0;
        }

        $owedIds = Release::query()
            ->whereIn('id', $ids)
            ->where('haspreview', self::HASPREVIEW_SKIPPED_BY_POLICY)
            ->whereNotIn('categories_id', $this->categoryIdsWithGenerationDisabled())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($owedIds === []) {
            return 0;
        }

        Release::query()
            ->whereIn('id', $owedIds)
            ->update(ReleaseClaimant::rependValues());

        if ($synchronize) {
            ReleaseSearchIndexSync::forIds($owedIds);
        }

        return count($owedIds);
    }

    /**
     * @return array<int, bool>
     */
    private function rootToggles(): array
    {
        if ($this->rootToggles === null) {
            $toggles = [];

            foreach (RootCategory::query()->pluck('generate_previews', 'id') as $id => $enabled) {
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
