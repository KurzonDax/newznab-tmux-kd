<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\TvReleaseFilters;
use App\Models\Category;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The TV releases screen's two queries (docs/proposals/tv-redesign/DATA-CONTRACT.md section 4):
 * a page of ids read from ix_releases_band_posted / _added, mirrored from the other end past
 * the middle of the list, and a count read from ix_releases_band_count and cached under the
 * browse cache version. Visibility is today's browse: the password setting and the user's
 * excluded categories, no nzbstatus test.
 */
final class TvReleaseList
{
    public function __construct(private readonly ReleaseBrowseService $releases) {}

    /** @param list<int> $exclusions */
    public function count(TvReleaseFilters $filters, array $exclusions): int
    {
        sort($exclusions);
        $password = $this->releases->showPasswords();
        $key = 'tv_releases_count:'.ReleaseBrowseService::cacheVersion().':'.md5($filters->countKey().'|'.implode(',', $exclusions).'|'.$password);
        $minutes = max(1, (int) config('nntmux.cache_expiry_short', 5)) * 2;

        return (int) Cache::remember($key, now()->addMinutes($minutes), fn (): int => $this->visible($filters, $exclusions, 'ix_releases_band_count')->count());
    }

    /**
     * The ids of the requested page in display order.
     *
     * @param  list<int>  $exclusions
     * @return list<int>
     */
    public function pageIds(TvReleaseFilters $filters, array $exclusions, int $total): array
    {
        $offset = ($filters->page - 1) * TvReleaseFilters::PER_PAGE;
        $limit = min(TvReleaseFilters::PER_PAGE, $total - $offset);
        if ($limit <= 0) {
            return [];
        }
        $ascending = $filters->ascending();
        $mirrored = $offset > intdiv($total, 2);
        if ($mirrored) {
            $offset = $total - $offset - $limit;
            $ascending = ! $ascending;
        }
        $direction = $ascending ? 'asc' : 'desc';
        $ids = $this->visible($filters, $exclusions, $filters->sortsByAdded() ? 'ix_releases_band_added' : 'ix_releases_band_posted')
            ->orderBy($filters->sortsByAdded() ? 'adddate' : 'postdate', $direction)->orderBy('id', $direction)
            ->offset($offset)->limit($limit)->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)->all();

        return $mirrored ? array_reverse($ids) : $ids;
    }

    /** @param list<int> $exclusions */
    private function visible(TvReleaseFilters $filters, array $exclusions, string $index): Builder
    {
        $query = DB::table('releases');
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->forceIndex($index);
        }
        $query->where('category_band', Category::TV_ROOT)
            ->whereRaw('passwordstatus '.$this->releases->showPasswords());
        if ($exclusions !== []) {
            $query->whereNotIn('categories_id', $exclusions);
        }
        if ($filters->categories !== []) {
            $query->whereIn('categories_id', $filters->categories);
        }
        if ($filters->resolutions !== []) {
            $query->whereIn('resolution', $filters->resolutionValues());
        }
        if ($filters->sources !== []) {
            $query->whereIn('source', $filters->sourceValues());
        }

        return $query;
    }
}
