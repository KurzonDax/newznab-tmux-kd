<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ConsoleReleaseRow;
use App\Data\ShelfReleaseRow;
use App\Enums\BrowseRoot;
use App\Models\Category;
use App\Models\Release;

/**
 * The release details page of a Books or PC release, or of a Console release with no game
 * (docs/proposals/books-console-pc-redesign/SPEC.md 5A): the release as its list's row, its
 * sub-category for the breadcrumb, the facts grid (a Console release adds Genre "—" after
 * Category), the PreDB block and Similar releases (today's ReleaseSearchService::searchSimilar(),
 * DATA-CONTRACT 4.3). No new storage is read.
 */
final class ShelfReleaseDetails
{
    public function __construct(
        private readonly ShelfReleaseRows $shelfRows,
        private readonly ConsoleReleaseRows $consoleRows,
        private readonly ReleaseSearchService $search,
    ) {}

    /**
     * @param  list<int>  $exclusions
     * @return array<string, mixed>
     */
    public function forRelease(Release $release, string $category, array $exclusions): array
    {
        $root = BrowseRoot::fromCategoryId((int) $release->categories_id);
        $console = $root === BrowseRoot::Console;
        $row = $this->rows($console, [(int) $release->id])[0] ?? null;
        abort_if($row === null, 404);
        // The breadcrumb names the sub-category alone ("Ebook"); the facts read the root by its label ("PC", not "Games").
        $subCategory = (string) (Category::query()->whereKey((int) $release->categories_id)->value('title') ?? '');
        $category = $subCategory === '' ? $category : $root->label().' > '.$subCategory;
        $facts = ReleaseDetailsFacts::grid($release, $row, $category);
        if ($console) {
            // A Console release with no game lists its Genre as unknown, right after Category (the prototype's facts).
            array_splice($facts, 1, 0, [['Genre', '—']]);
        }

        return [
            'row' => $row,
            'category' => $category,
            'subCategory' => $subCategory,
            'facts' => $facts,
            'predb' => ReleaseDetailsFacts::predb((int) $release->predb_id),
            'similar' => $this->similar($release, $console, $exclusions),
        ];
    }

    /**
     * A sub-category's place in its list's Category menu order (BookReleaseList, PcReleaseList,
     * ConsoleReleaseList::CATEGORY_ORDER), the Similar table's Category sort key; one the order does
     * not list (an admin's own) comes after the last listed one, as the menu lists it.
     */
    public static function categoryPosition(int $categoryId): int
    {
        $order = match (BrowseRoot::fromCategoryId($categoryId)) {
            BrowseRoot::Books => BookReleaseList::CATEGORY_ORDER,
            BrowseRoot::Games => PcReleaseList::CATEGORY_ORDER,
            BrowseRoot::Console => ConsoleReleaseList::CATEGORY_ORDER,
            default => [],
        };
        $position = array_search($categoryId, $order, true);

        return $position === false ? count($order) : $position;
    }

    /**
     * Similar releases: today's search (the first two words of the name in the release's root
     * categories, the viewer's excluded categories and password setting applied, without this
     * release), newest posted first as the table's Posted heading says (ties: the newer id first).
     *
     * @param  list<int>  $exclusions
     * @return list<ShelfReleaseRow>|list<ConsoleReleaseRow>
     */
    private function similar(Release $release, bool $console, array $exclusions): array
    {
        $found = $this->search->searchSimilar((int) $release->id, (string) $release->searchname, $exclusions);
        if (! is_array($found)) {
            return [];
        }
        $ids = array_values(array_filter(array_map(static fn (mixed $match): int => (int) $match['id'], $found), static fn (int $id): bool => $id !== (int) $release->id));
        if ($ids === []) {
            return [];
        }
        $rows = $this->rows($console, $ids);
        usort($rows, static fn (ShelfReleaseRow|ConsoleReleaseRow $a, ShelfReleaseRow|ConsoleReleaseRow $b): int => [$b->postedAt, $b->id] <=> [$a->postedAt, $a->id]);

        return $rows;
    }

    /**
     * @param  list<int>  $ids
     * @return list<ShelfReleaseRow>|list<ConsoleReleaseRow>
     */
    private function rows(bool $console, array $ids): array
    {
        return $console ? $this->consoleRows->load($ids, false) : $this->shelfRows->load($ids, false);
    }
}
