<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\AdultReleaseFilters;
use App\Data\ReleaseListFilters;
use App\Models\Category;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Adult releases screen's queries (BandReleaseList, docs/proposals/adult-redesign/DATA-CONTRACT.md
 * 4.1 and 4.2): band 6000 read from the release index, with no title filters. The name search is
 * one more condition on that index, today's name test (ReleaseBrowserQuery); while it is set the
 * count reads the page's index too, since the band count index holds no name.
 *
 * @extends BandReleaseList<AdultReleaseFilters>
 */
final class AdultReleaseList extends BandReleaseList
{
    /** The Category menu's order (SPEC 5.2): DVD … WEBDL, Other; other sub-categories with releases follow. */
    public const CATEGORY_ORDER = [Category::XXX_DVD, Category::XXX_WMV, Category::XXX_XVID, Category::XXX_X264, Category::XXX_CLIPHD,
        Category::XXX_CLIPSD, Category::XXX_UHD, Category::XXX_VR, Category::XXX_PACK, Category::XXX_IMAGESET, Category::XXX_SD,
        Category::XXX_WEBDL, Category::XXX_OTHER];

    protected function band(): int
    {
        return Category::XXX_ROOT;
    }

    protected function cachePrefix(): string
    {
        return 'adult_releases';
    }

    /** @param AdultReleaseFilters $filters */
    protected function countIndex(ReleaseListFilters $filters): string
    {
        return $filters->search === '' ? 'ix_releases_band_count' : $this->releaseIndex($filters);
    }

    /**
     * @param  AdultReleaseFilters  $filters
     * @param  list<int>  $exclusions
     */
    public function readIndex(ReleaseListFilters $filters, array $exclusions): string
    {
        return $this->releaseIndex($filters);
    }

    /**
     * The Category menu: the Adult sub-categories the user may see that hold releases (counted for
     * all users) or are chosen, in CATEGORY_ORDER, then any other such sub-category.
     *
     * @param  list<int>  $exclusions
     * @param  list<int>  $chosen  the sub-categories the URL or the remembered filters tick
     * @return array<int, string> id => title, in menu order
     */
    public function categoryMenu(array $exclusions, array $chosen = []): array
    {
        return $this->orderedCategoryMenu(self::CATEGORY_ORDER, $exclusions, $chosen);
    }

    /**
     * @param  AdultReleaseFilters  $filters
     * @param  list<int>  $exclusions
     */
    protected function visible(ReleaseListFilters $filters, array $exclusions, string $index): Builder
    {
        $query = DB::table('releases');
        if ($this->isMariaDb()) {
            $query->forceIndex($index);
        }
        $this->whereRelease($query->where('releases.category_band', $this->band()), $filters, $exclusions);
        if ($filters->search !== '') {
            // Today's name search (ReleaseBrowserQuery): the display name, or the search name when it is empty, with %, _ and ! literal.
            $query->whereRaw("COALESCE(NULLIF(TRIM(releases.display_name), ''), releases.searchname) LIKE ? ESCAPE '!'",
                ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters->search).'%']);
        }

        return $query;
    }
}
