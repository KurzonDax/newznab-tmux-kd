<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\BookPcReleaseFilters;
use App\Models\Category;

/**
 * The PC releases list's queries (ShelfReleaseList): band 4000.
 *
 * @extends ShelfReleaseList<BookPcReleaseFilters>
 */
final class PcReleaseList extends ShelfReleaseList
{
    /** The Category menu's order (SPEC 5.2): 0day, ISO, Mac, Phone-Other, Games, iOS, Android, Other. */
    public const CATEGORY_ORDER = [Category::PC_0DAY, Category::PC_ISO, Category::PC_MAC, Category::PC_PHONE_OTHER, Category::PC_GAMES,
        Category::PC_PHONE_IOS, Category::PC_PHONE_ANDROID, Category::PC_OTHER];

    protected function band(): int
    {
        return Category::PC_ROOT;
    }

    protected function cachePrefix(): string
    {
        return 'pc_releases';
    }
}
