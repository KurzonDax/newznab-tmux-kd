<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\Request;

/**
 * What the Book and PC releases lists show (docs/proposals/books-console-pc-redesign/SPEC.md 5.2,
 * Appendix B): Category and Completion, the name search, the sort and the page. PC is the Books
 * screen on PC data.
 */
final readonly class BookPcReleaseFilters extends ShelfReleaseFilters
{
    /**
     * @param  list<int>  $menuCategories  the sub-category ids the menu lists, in menu order
     * @param  int  $root  the list's root category (Category::BOOKS_ROOT or Category::PC_ROOT)
     */
    public static function forList(Request $request, array $menuCategories, mixed $savedSort, int $root): self
    {
        return new self(...self::shelfArguments($request, $menuCategories, $savedSort, $root));
    }

    /**
     * What is set, for the empty result: "Comics or Ebook · 95%+ complete · names containing “text”".
     *
     * @param  array<int, string>  $categoryMenu
     */
    public function describe(array $categoryMenu): string
    {
        return $this->describedWithSearch($this->describeRelease($categoryMenu, []));
    }

    public function any(): bool
    {
        return $this->anyWithSearch($this->anyRelease());
    }

    public function withPage(int $page): static
    {
        return new self(categories: $this->categories, sort: $this->sort, page: $page, completion: $this->completion, excludeOther: $this->excludeOther,
            search: $this->search);
    }

    /** @return array<string, list<int|string>|int|string> the URL query for this page; page 1 carries no page */
    public function query(?int $page = null): array
    {
        return $this->queryWithSearch($this->releaseQuery(), $page ?? $this->page);
    }

    public function countKey(): string
    {
        return $this->countKeyWithSearch($this->releaseCountKey());
    }
}
