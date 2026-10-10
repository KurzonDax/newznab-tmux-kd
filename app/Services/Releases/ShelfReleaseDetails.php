<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ConsoleReleaseRow;
use App\Data\GenericListContext;
use App\Data\GenericReleaseRow;
use App\Data\ShelfReleaseRow;
use App\Enums\BrowseRoot;
use App\Models\Category;
use App\Models\Release;

/**
 * The release details page of a Books or PC release, of a Console release with no game
 * (docs/proposals/books-console-pc-redesign/SPEC.md 5A) and of an Other release
 * (docs/proposals/generic-release-lists/SPEC.md 6): the release as its list's row, its
 * sub-category for the breadcrumb, the facts grid (a Console release adds Genre "—" after
 * Category), the PreDB block and Similar releases (today's ReleaseSearchService::searchSimilar(),
 * DATA-CONTRACT 4.3). An Other release is a generic-list row (its Reported / Response chips, its
 * pictures and clip) and its breadcrumb names the generic list it was opened from. No new
 * storage is read.
 */
final class ShelfReleaseDetails
{
    public function __construct(
        private readonly ShelfReleaseRows $shelfRows,
        private readonly ConsoleReleaseRows $consoleRows,
        private readonly GenericReleaseRows $genericRows,
        private readonly ReleaseSearchService $search,
    ) {}

    /**
     * @param  list<int>  $exclusions
     * @param  string|null  $referer  the Referer header, which names the generic list an Other release was opened from
     * @return array<string, mixed>
     */
    public function forRelease(Release $release, string $category, array $exclusions, ?string $referer = null): array
    {
        $root = BrowseRoot::fromCategoryId((int) $release->categories_id);
        $kind = $root === BrowseRoot::Console ? 'console' : ($root === BrowseRoot::Other ? 'generic' : 'shelf');
        $row = $this->rows($kind, [(int) $release->id])[0] ?? null;
        abort_if($row === null, 404);
        // The breadcrumb names the sub-category alone ("Ebook"); the facts read the root by its label ("PC", not "Games").
        $subCategory = (string) (Category::query()->whereKey((int) $release->categories_id)->value('title') ?? '');
        $category = $subCategory === '' ? $category : $root->label().' > '.$subCategory;
        $facts = ReleaseDetailsFacts::grid($release, $row, $category);
        if ($kind === 'console') {
            // A Console release with no game lists its Genre as unknown, right after Category (the prototype's facts).
            array_splice($facts, 1, 0, [['Genre', '—']]);
        }
        $origin = null;
        if ($kind === 'generic') {
            $list = GenericListContext::fromReferer($referer) ?? GenericListContext::all();
            $origin = ['label' => $list->heading(), 'url' => $list->url()];
        }

        return [
            'row' => $row,
            'category' => $category,
            'subCategory' => $subCategory,
            'facts' => $facts,
            'predb' => ReleaseDetailsFacts::predb((int) $release->predb_id),
            'similar' => $this->similar($release, $kind, $exclusions),
            'clip' => $row instanceof GenericReleaseRow ? $row->clip : null,
            'clipSeconds' => $row instanceof GenericReleaseRow && $row->clip !== null ? ReleaseDetailsFacts::clipSeconds((int) $release->id) : null,
            'origin' => $origin,
        ];
    }

    /**
     * A sub-category's place in its list's Category menu order (BookReleaseList, PcReleaseList,
     * ConsoleReleaseList, AudioReleaseList::CATEGORY_ORDER; Misc then Hashed on Other), the
     * Similar table's Category sort key; one the order does not list (an admin's own) comes after
     * the last listed one, as the menu lists it.
     */
    public static function categoryPosition(int $categoryId): int
    {
        $order = match (BrowseRoot::fromCategoryId($categoryId)) {
            BrowseRoot::Books => BookReleaseList::CATEGORY_ORDER,
            BrowseRoot::Games => PcReleaseList::CATEGORY_ORDER,
            BrowseRoot::Console => ConsoleReleaseList::CATEGORY_ORDER,
            BrowseRoot::Audio => AudioReleaseList::CATEGORY_ORDER,
            BrowseRoot::Other => GenericListContext::otherCategories(),
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
     * @return list<ShelfReleaseRow>|list<ConsoleReleaseRow>|list<GenericReleaseRow>
     */
    private function similar(Release $release, string $kind, array $exclusions): array
    {
        $found = $this->search->searchSimilar((int) $release->id, (string) $release->searchname, $exclusions);
        if (! is_array($found)) {
            return [];
        }
        $ids = array_values(array_filter(array_map(static fn (mixed $match): int => (int) $match['id'], $found), static fn (int $id): bool => $id !== (int) $release->id));
        if ($ids === []) {
            return [];
        }
        $rows = $this->rows($kind, $ids);
        usort($rows, static fn (ShelfReleaseRow|ConsoleReleaseRow|GenericReleaseRow $a, ShelfReleaseRow|ConsoleReleaseRow|GenericReleaseRow $b): int => [$b->postedAt, $b->id] <=> [$a->postedAt, $a->id]);

        return $rows;
    }

    /**
     * @param  'shelf'|'console'|'generic'  $kind
     * @param  list<int>  $ids
     * @return list<ShelfReleaseRow>|list<ConsoleReleaseRow>|list<GenericReleaseRow>
     */
    private function rows(string $kind, array $ids): array
    {
        return match ($kind) {
            'console' => $this->consoleRows->load($ids, false),
            'generic' => $this->genericRows->load($ids, false),
            default => $this->shelfRows->load($ids, false),
        };
    }
}
