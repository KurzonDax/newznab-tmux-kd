<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseListFilters;
use App\Data\ShelfReleaseFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Books, Console and PC releases lists' queries (BandReleaseList,
 * docs/proposals/books-console-pc-redesign/DATA-CONTRACT.md 4.1): the band read from the release
 * index, as the Adult list (AdultReleaseList). The name search is one more condition on that
 * index, today's name test (ReleaseBrowserQuery); while it is set the count reads the page's index
 * too, since the band count index holds no name.
 *
 * @template TFilters of ShelfReleaseFilters
 *
 * @extends BandReleaseList<TFilters>
 */
abstract class ShelfReleaseList extends BandReleaseList
{
    /**
     * The Category menu's order (SPEC 5.2): the site's order, ascending id, Other last; other
     * sub-categories with releases follow.
     *
     * @var list<int>
     */
    public const CATEGORY_ORDER = [];

    /** The name the search reads: the display name, or the search name when it is empty. */
    protected const string RELEASE_NAME = "COALESCE(NULLIF(TRIM(releases.display_name), ''), releases.searchname)";

    /** @param ShelfReleaseFilters $filters */
    protected function countIndex(ReleaseListFilters $filters): string
    {
        return $filters->search === '' ? 'ix_releases_band_count' : $this->releaseIndex($filters);
    }

    /**
     * @param  ShelfReleaseFilters  $filters
     * @param  list<int>  $exclusions
     */
    public function readIndex(ReleaseListFilters $filters, array $exclusions): string
    {
        return $this->releaseIndex($filters);
    }

    /**
     * The Category menu: the band's sub-categories the user may see that hold releases (counted
     * for all users) or are chosen, in CATEGORY_ORDER, then any other such sub-category.
     *
     * @param  list<int>  $exclusions
     * @param  list<int>  $chosen  the sub-categories the URL or the remembered filters tick
     * @return array<int, string> id => title, in menu order
     */
    public function categoryMenu(array $exclusions, array $chosen = []): array
    {
        return $this->orderedCategoryMenu(static::CATEGORY_ORDER, $exclusions, $chosen);
    }

    /**
     * @param  ShelfReleaseFilters  $filters
     * @param  list<int>  $exclusions
     */
    protected function visible(ReleaseListFilters $filters, array $exclusions, string $index): Builder
    {
        $query = DB::table('releases');
        if ($this->isMariaDb()) {
            $query->forceIndex($index);
        }

        return $this->released($query->where('releases.category_band', $this->band()), $filters, $exclusions);
    }

    /**
     * The release filters and the name search on `releases`.
     *
     * @param  list<int>  $exclusions
     */
    protected function released(Builder $query, ShelfReleaseFilters $filters, array $exclusions): Builder
    {
        $this->whereRelease($query, $filters, $exclusions);
        if ($filters->search !== '') {
            $this->whereSearch($query, $filters->search);
        }

        return $query;
    }

    /**
     * Today's name search (ReleaseBrowserQuery): the display name, or the search name when it is
     * empty, contains the text, with %, _ and ! literal.
     */
    protected function whereSearch(Builder $query, string $search): void
    {
        $query->whereRaw(self::RELEASE_NAME." LIKE ? ESCAPE '!'", [self::likeContaining($search)]);
    }

    /** The LIKE pattern of names containing the text, escaped with '!'. */
    protected static function likeContaining(string $search): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
    }
}
