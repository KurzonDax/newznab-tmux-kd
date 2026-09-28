<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseListFilters;
use App\Data\TvReleaseFilters;
use App\Models\Category;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The TV releases screen's queries (BandReleaseList, docs/proposals/tv-redesign/DATA-CONTRACT.md
 * section 4 and docs/proposals/movies-redesign/DATA-CONTRACT.md 4.6).
 *
 * The index: with a show filter set, the matching shows' releases on ix_releases_videos_posted /
 * _added while the show filters alone match fewer than $showReadLimit releases (never mirrored),
 * else the band index with videos_id IN (the matching shows). Otherwise the release index.
 *
 * @extends BandReleaseList<TvReleaseFilters>
 */
final class TvReleaseList extends BandReleaseList
{
    /** The list size under which a show-filtered list is read from the matching shows. */
    public const int SHOW_READ_LIMIT = 50_000;

    public function __construct(
        ReleaseBrowseService $releases,
        private readonly TvShowWall $wall,
        private readonly int $showReadLimit = self::SHOW_READ_LIMIT,
    ) {
        parent::__construct($releases);
    }

    protected function band(): int
    {
        return Category::TV_ROOT;
    }

    protected function cachePrefix(): string
    {
        return 'tv_releases';
    }

    /** @param TvReleaseFilters $filters */
    protected function countIndex(ReleaseListFilters $filters): string
    {
        return $filters->shows->any() ? 'ix_releases_videos_posted' : 'ix_releases_band_count';
    }

    /**
     * The index this user's page of the list is read from.
     *
     * @param  TvReleaseFilters  $filters
     * @param  list<int>  $exclusions
     */
    public function readIndex(ReleaseListFilters $filters, array $exclusions): string
    {
        return $this->pageIndex($filters, $filters->shows->any() ? $this->count(new TvReleaseFilters(shows: $filters->shows), $exclusions) : 0);
    }

    /**
     * The index a page of the list is read from. $showReleases is the visible releases the show
     * filters match on their own (the release filters left out), the count that decides the
     * show-filtered read: measured on the catalogue, English shows plus UHD (12,583 releases of
     * 94,088) read 59 ms from the shows and 0.7-12 ms from the band index.
     */
    public function pageIndex(TvReleaseFilters $filters, int $showReleases): string
    {
        if ($filters->shows->any()) {
            $date = $filters->sortsByAdded() ? 'added' : 'posted';

            return $showReleases < $this->showReadLimit ? 'ix_releases_videos_'.$date : 'ix_releases_band_'.$date;
        }

        return $this->releaseIndex($filters);
    }

    /**
     * The list's show menus: the wall's, with the Language menu ordered by the languages' TV
     * releases (counted for all users; codes that share a name count together), ties by name.
     *
     * @param  list<int>  $exclusions
     * @return array{genre: array<int, string>, decade: array<int, string>, language: array<string, string>, language_codes: array<string, list<string>>, network: array<int, string>, rating: array<string, string>, status: array<string, string>}
     */
    public function showOptions(array $exclusions): array
    {
        $options = $this->wall->options($exclusions);
        /** @var array<string, int> $releases */
        $releases = Cache::remember('tv_releases_language_counts', self::MENU_SECONDS, function (): array {
            $perShow = DB::table('releases');
            if ($this->isMariaDb()) {
                $perShow->forceIndex('ix_releases_band_posted');
            }
            $perShow->select('videos_id')->selectRaw('COUNT(*) AS releases')->where('category_band', Category::TV_ROOT)
                ->where('videos_id', '>', 0)->groupBy('videos_id');

            return DB::query()->fromSub($perShow, 'g')->join('tv_info as t', 't.videos_id', '=', 'g.videos_id')
                ->groupBy('t.original_language')->selectRaw('t.original_language AS code, SUM(g.releases) AS releases')->get()
                ->mapWithKeys(static fn (object $row): array => [(string) $row->code => (int) $row->releases])->all();
        });
        $languages = $options['language'];
        $count = [];
        foreach (array_keys($languages) as $value) {
            $count[$value] = array_sum(array_map(static fn (string $code): int => $releases[$code] ?? 0, $options['language_codes'][$value] ?? [(string) $value]));
        }
        uksort($languages, static fn (int|string $a, int|string $b): int => [$count[$b], $languages[$a]] <=> [$count[$a], $languages[$b]]);
        $options['language'] = $languages;

        return $options;
    }

    /**
     * @param  TvReleaseFilters  $filters
     * @param  list<int>  $exclusions
     */
    protected function visible(ReleaseListFilters $filters, array $exclusions, string $index): Builder
    {
        $query = DB::table('releases');
        if ($this->isMariaDb()) {
            $query->forceIndex($index);
        }
        $this->whereRelease($query->where('releases.category_band', $this->band()), $filters, $exclusions);
        if ($filters->shows->any()) {
            $query->whereIn('releases.videos_id', $this->wall->matchingShows($filters->shows)->select('v.id'));
        }

        return $query;
    }
}
