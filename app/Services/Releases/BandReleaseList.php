<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseListFilters;
use App\Models\Category;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The queries a section's releases list shares (docs/proposals/tv-redesign/DATA-CONTRACT.md
 * section 4, docs/proposals/movies-redesign/DATA-CONTRACT.md 4.1 and 4.6): a page of ids read
 * from the index the filters choose, mirrored from the other end past the middle of the list
 * when that index is the band's, and a count cached under the browse cache version. Visibility
 * is today's browse: the password setting and the user's excluded categories, no nzbstatus test.
 *
 * The release index: the _cat_ / _res_ / _src_ index of the set filter whose chosen values hold
 * the fewest releases (the band's counts for all users), unless no such filter is set or those
 * values hold more than half of the band: then the band index. Completion and Audio are
 * conditions on the chosen index, never the driving read. Each section adds its title filters
 * (TvReleaseList's shows, MovieReleaseList's films) and the read they drive.
 *
 * @template TFilters of ReleaseListFilters
 */
abstract class BandReleaseList
{
    /** How long the counts that steer the index choice and order the menus are kept. */
    protected const int MENU_SECONDS = 3600;

    public function __construct(protected readonly ReleaseBrowseService $releases) {}

    /** The section's root category (its releases' category_band). */
    abstract protected function band(): int;

    /** The prefix of the section's cache keys ("tv_releases"). */
    abstract protected function cachePrefix(): string;

    /**
     * The visible releases the filters keep, read from $index; selects nothing yet.
     *
     * @param  TFilters  $filters
     * @param  list<int>  $exclusions
     */
    abstract protected function visible(ReleaseListFilters $filters, array $exclusions, string $index): Builder;

    /**
     * The index this user's page of the list is read from.
     *
     * @param  TFilters  $filters
     * @param  list<int>  $exclusions
     */
    abstract public function readIndex(ReleaseListFilters $filters, array $exclusions): string;

    /**
     * The index the list's count is read from.
     *
     * @param  TFilters  $filters
     */
    abstract protected function countIndex(ReleaseListFilters $filters): string;

    /**
     * @param  TFilters  $filters
     * @param  list<int>  $exclusions
     */
    public function count(ReleaseListFilters $filters, array $exclusions): int
    {
        sort($exclusions);
        $password = $this->releases->showPasswords();
        $key = $this->cachePrefix().'_count:'.ReleaseBrowseService::cacheVersion().':'.md5($filters->countKey().'|'.implode(',', $exclusions).'|'.$password);
        $minutes = max(1, (int) config('nntmux.cache_expiry_short', 5)) * 2;

        return (int) Cache::remember($key, now()->addMinutes($minutes), fn (): int => $this->countVisible($filters, $exclusions));
    }

    /**
     * The uncached count: the visible releases read from the count index.
     *
     * @param  TFilters  $filters
     * @param  list<int>  $exclusions
     */
    protected function countVisible(ReleaseListFilters $filters, array $exclusions): int
    {
        return $this->visible($filters, $exclusions, $this->countIndex($filters))->count();
    }

    /**
     * The ids of the requested page in display order.
     *
     * @param  TFilters  $filters
     * @param  list<int>  $exclusions
     * @return list<int>
     */
    public function pageIds(ReleaseListFilters $filters, array $exclusions, int $total): array
    {
        $offset = ($filters->page - 1) * ReleaseListFilters::PER_PAGE;
        $limit = min(ReleaseListFilters::PER_PAGE, $total - $offset);
        if ($limit <= 0) {
            return [];
        }
        $index = $this->readIndex($filters, $exclusions);
        $ascending = $filters->ascending();
        // A read driven from the titles sorts their releases, the same cost on every page.
        $mirrored = str_starts_with($index, 'ix_releases_band_') && $offset > intdiv($total, 2);
        if ($mirrored) {
            $offset = $total - $offset - $limit;
            $ascending = ! $ascending;
        }
        $direction = $ascending ? 'asc' : 'desc';
        $ids = $this->visible($filters, $exclusions, $index)
            ->orderBy($filters->sortsByAdded() ? 'releases.adddate' : 'releases.postdate', $direction)->orderBy('releases.id', $direction)
            ->offset($offset)->limit($limit)->pluck('releases.id')
            ->map(static fn (mixed $id): int => (int) $id)->all();

        return $mirrored ? array_reverse($ids) : $ids;
    }

    /**
     * The band index, or the _cat_ / _res_ / _src_ index the release filters choose.
     *
     * @param  TFilters  $filters
     */
    protected function releaseIndex(ReleaseListFilters $filters): string
    {
        $date = $filters->sortsByAdded() ? 'added' : 'posted';
        $counts = $this->valueCounts();
        $chosen = array_filter([
            'cat' => $filters->categories === [] ? null : array_sum(array_map(static fn (int $id): int => $counts['category'][$id] ?? 0, $filters->categories)),
            'res' => $filters->resolutions === [] ? null : array_sum(array_map(static fn (int $value): int => $counts['resolution'][$value] ?? 0, $filters->resolutionValues())),
            'src' => $filters->sources === [] ? null : array_sum(array_map(static fn (int $value): int => $counts['source'][$value] ?? 0, $filters->sourceValues())),
        ], static fn (?int $releases): bool => $releases !== null);
        if ($chosen === [] || min($chosen) > $counts['band'] / 2) {
            return 'ix_releases_band_'.$date;
        }

        return 'ix_releases_band_'.array_search(min($chosen), $chosen, true).'_'.$date;
    }

    /**
     * The Audio menu: the languages the section's releases have (for all users), English first,
     * then A to Z by name, then Unknown. The order is applied after the cache, so a menu cached
     * in another order is served in this one.
     *
     * @return array<int|string, string> URL value (languages.id, or AUDIO_UNKNOWN) => name
     */
    public function audioMenu(): array
    {
        $languages = Cache::remember($this->cachePrefix().'_audio_menu', self::MENU_SECONDS, function (): array {
            return DB::table('release_audio_languages as a')->join('releases as r', 'r.id', '=', 'a.releases_id')
                ->join('languages as l', 'l.id', '=', 'a.languages_id')->where('r.category_band', $this->band())
                ->groupBy('a.languages_id', 'l.name')->pluck('l.name', 'a.languages_id')->mapWithKeys(static fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])->all();
        });
        uasort($languages, static fn (string $a, string $b): int => [$a !== 'English', mb_strtolower($a), $a] <=> [$b !== 'English', mb_strtolower($b), $b]);

        return $languages + [ReleaseListFilters::AUDIO_UNKNOWN => 'Unknown'];
    }

    /**
     * A Category menu: the band's sub-categories the user may see, in $order and then any other,
     * kept while they hold releases (counted for all users) or are chosen. A chosen sub-category
     * with no release stays listed, so the list shows its empty result rather than every release
     * (issue #887); a hidden one is never listed.
     *
     * @param  list<int>  $order
     * @param  list<int>  $exclusions
     * @param  list<int>  $chosen  the sub-categories the URL or the remembered filters tick
     * @return array<int, string> id => title, in menu order
     */
    protected function orderedCategoryMenu(array $order, array $exclusions, array $chosen): array
    {
        $visible = [];
        foreach (Category::getForMenu($exclusions) as $root) {
            if ((int) $root['id'] === $this->band()) {
                $visible = array_column($root['categories'], 'title', 'id');
            }
        }
        $held = $this->valueCounts()['category'];
        $menu = [];
        foreach ([...$order, ...array_keys($visible)] as $id) {
            if (isset($visible[$id]) && (($held[$id] ?? 0) > 0 || in_array((int) $id, $chosen, true))) {
                $menu[(int) $id] ??= (string) $visible[$id];
            }
        }

        return $menu;
    }

    /**
     * The band's releases per Category, Resolution and Source value and in all, for all users.
     *
     * @return array{category: array<int, int>, resolution: array<int, int>, source: array<int, int>, band: int}
     */
    protected function valueCounts(): array
    {
        return Cache::remember($this->cachePrefix().'_value_counts', self::MENU_SECONDS, function (): array {
            $query = DB::table('releases');
            if ($this->isMariaDb()) {
                $query->forceIndex('ix_releases_band_count');
            }
            $counts = ['category' => [], 'resolution' => [], 'source' => [], 'band' => 0];
            foreach ($query->where('category_band', $this->band())->groupBy('categories_id', 'resolution', 'source')
                ->selectRaw('categories_id, resolution, source, COUNT(*) AS releases')->get() as $row) {
                $releases = (int) $row->releases;
                $counts['category'][(int) $row->categories_id] = ($counts['category'][(int) $row->categories_id] ?? 0) + $releases;
                $counts['resolution'][(int) $row->resolution] = ($counts['resolution'][(int) $row->resolution] ?? 0) + $releases;
                $counts['source'][(int) $row->source] = ($counts['source'][(int) $row->source] ?? 0) + $releases;
                $counts['band'] += $releases;
            }

            return $counts;
        });
    }

    /**
     * The visibility and the release filters on `releases`: the password setting, the user's
     * excluded categories, Category, Resolution, Source, Completion and Audio.
     *
     * @param  TFilters  $filters
     * @param  list<int>  $exclusions
     */
    protected function whereRelease(Builder $query, ReleaseListFilters $filters, array $exclusions): Builder
    {
        $query->whereRaw('releases.passwordstatus '.$this->releases->showPasswords());
        if ($exclusions !== []) {
            $query->whereNotIn('releases.categories_id', $exclusions);
        }
        if ($filters->categories !== []) {
            $query->whereIn('releases.categories_id', $filters->categories);
        }
        if ($filters->resolutions !== []) {
            $query->whereIn('releases.resolution', $filters->resolutionValues());
        }
        if ($filters->sources !== []) {
            $query->whereIn('releases.source', $filters->sourceValues());
        }
        if ($filters->completion !== null) {
            $query->where('releases.completion', '>=', $filters->completion);
        }
        $this->whereAudio($query, $filters);

        return $query;
    }

    /**
     * A language is an EXISTS; Unknown alone is an anti-join (MariaDB copies the whole table for
     * a NOT EXISTS on every query); both together are either test, as NOT EXISTS, since the
     * anti-join would repeat a release once per language.
     *
     * @param  TFilters  $filters
     */
    private function whereAudio(Builder $query, ReleaseListFilters $filters): void
    {
        $languages = $filters->audioLanguages();
        $has = static fn (Builder $exists) => $exists->selectRaw('1')->from('release_audio_languages as al')
            ->whereColumn('al.releases_id', 'releases.id')->whereIn('al.languages_id', $languages);
        if (! $filters->audioUnknown()) {
            if ($languages !== []) {
                $query->whereExists($has);
            }

            return;
        }
        if ($languages === []) {
            $query->leftJoin('release_audio_languages as a', 'a.releases_id', '=', 'releases.id')->whereNull('a.releases_id');

            return;
        }
        $query->where(static fn (Builder $either) => $either->whereExists($has)->orWhereNotExists(
            static fn (Builder $none) => $none->selectRaw('1')->from('release_audio_languages as an')->whereColumn('an.releases_id', 'releases.id')));
    }

    protected function isMariaDb(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
}
