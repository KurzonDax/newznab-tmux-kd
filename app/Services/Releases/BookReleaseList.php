<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\BookPcReleaseFilters;
use App\Models\Category;

/**
 * The Book releases list's queries (ShelfReleaseList): band 7000.
 *
 * @extends ShelfReleaseList<BookPcReleaseFilters>
 */
final class BookReleaseList extends ShelfReleaseList
{
    /** The Category menu's order (SPEC 5.2): Magazines, Ebook, Comics, Technical, Foreign, Other. */
    public const CATEGORY_ORDER = [Category::BOOKS_MAGAZINES, Category::BOOKS_EBOOK, Category::BOOKS_COMICS, Category::BOOKS_TECHNICAL,
        Category::BOOKS_FOREIGN, Category::BOOKS_UNKNOWN];

    protected function band(): int
    {
        return Category::BOOKS_ROOT;
    }

    protected function cachePrefix(): string
    {
        return 'book_releases';
    }
}
