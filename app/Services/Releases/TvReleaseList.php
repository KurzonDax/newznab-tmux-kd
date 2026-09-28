<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\TvReleaseFilters;
use App\Models\Category;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The TV releases screen's queries (docs/proposals/tv-redesign/DATA-CONTRACT.md section 4 and
 * docs/proposals/movies-redesign/DATA-CONTRACT.md 4.1 and 4.6): a page of ids read from the index
 * the filters choose, mirrored from the other end past the middle of the list, and a count
 * cached under the browse cache version. Visibility is today's browse: the password setting and
 * the user's excluded categories, no nzbstatus test.
 *
 * The index: with a show filter set, the matching shows' releases on ix_releases_videos_posted /
 * _added while the show filters alone match fewer than $showReadLimit releases (never mirrored),
 * else the band index with videos_id IN (the matching shows). Otherwise the _cat_ / _res_ / _src_ index of the
 * set filter whose chosen values hold the fewest releases (the band's counts for all users),
 * unless no such filter is set or those values hold more than half of the band: then the band
 * index. Completion and Audio are conditions on the chosen index, never the driving read.
 */
final class TvReleaseList
{
    /** The list size under which a show-filtered list is read from the matching shows. */
    public const int SHOW_READ_LIMIT = 50_000;

    /** How long the counts that steer the index choice and order the menus are kept. */
    private const int MENU_SECONDS = 3600;

    public function __construct(
        private readonly ReleaseBrowseService $releases,
        private readonly TvShowWall $wall,
        private readonly int $showReadLimit = self::SHOW_READ_LIMIT,
    ) {}

    /** @param list<int> $exclusions */
    public function count(TvReleaseFilters $filters, array $exclusions): int
    {
        sort($exclusions);
        $password = $this->releases->showPasswords();
        $key = 'tv_releases_count:'.ReleaseBrowseService::cacheVersion().':'.md5($filters->countKey().'|'.implode(',', $exclusions).'|'.$password);
        $minutes = max(1, (int) config('nntmux.cache_expiry_short', 5)) * 2;
        $index = $filters->shows->any() ? 'ix_releases_videos_posted' : 'ix_releases_band_count';

        return (int) Cache::remember($key, now()->addMinutes($minutes), fn (): int => $this->visible($filters, $exclusions, $index)->count());
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
        $index = $this->readIndex($filters, $exclusions);
        $ascending = $filters->ascending();
        // The show-side read sorts the matching shows' releases, the same cost on every page.
        $mirrored = ! str_starts_with($index, 'ix_releases_videos_') && $offset > intdiv($total, 2);
        if ($mirrored) {
            $offset = $total - $offset - $limit;
            $ascending = ! $ascending;
        }
        $direction = $ascending ? 'asc' : 'desc';
        $ids = $this->visible($filters, $exclusions, $index)
            ->orderBy($filters->sortsByAdded() ? 'adddate' : 'postdate', $direction)->orderBy('id', $direction)
            ->offset($offset)->limit($limit)->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)->all();

        return $mirrored ? array_reverse($ids) : $ids;
    }

    /**
     * The index this user's page of the list is read from.
     *
     * @param  list<int>  $exclusions
     */
    public function readIndex(TvReleaseFilters $filters, array $exclusions): string
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
        $date = $filters->sortsByAdded() ? 'added' : 'posted';
        if ($filters->shows->any()) {
            return $showReleases < $this->showReadLimit ? 'ix_releases_videos_'.$date : 'ix_releases_band_'.$date;
        }
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
     * The Audio menu: the languages TV releases have, most releases first (counted for all
     * users), then Unknown.
     *
     * @return array<int|string, string> URL value (languages.id, or AUDIO_UNKNOWN) => name
     */
    public function audioMenu(): array
    {
        $languages = Cache::remember('tv_releases_audio_menu', self::MENU_SECONDS, static function (): array {
            return DB::table('release_audio_languages as a')->join('releases as r', 'r.id', '=', 'a.releases_id')
                ->join('languages as l', 'l.id', '=', 'a.languages_id')->where('r.category_band', Category::TV_ROOT)
                ->groupBy('a.languages_id', 'l.name')->orderByRaw('COUNT(*) DESC')->orderBy('l.name')
                ->pluck('l.name', 'a.languages_id')->mapWithKeys(static fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])->all();
        });

        return $languages + [TvReleaseFilters::AUDIO_UNKNOWN => 'Unknown'];
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
     * The band's releases per Category, Resolution and Source value and in all, for all users.
     *
     * @return array{category: array<int, int>, resolution: array<int, int>, source: array<int, int>, band: int}
     */
    private function valueCounts(): array
    {
        return Cache::remember('tv_releases_value_counts', self::MENU_SECONDS, function (): array {
            $query = DB::table('releases');
            if ($this->isMariaDb()) {
                $query->forceIndex('ix_releases_band_count');
            }
            $counts = ['category' => [], 'resolution' => [], 'source' => [], 'band' => 0];
            foreach ($query->where('category_band', Category::TV_ROOT)->groupBy('categories_id', 'resolution', 'source')
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

    /** @param list<int> $exclusions */
    private function visible(TvReleaseFilters $filters, array $exclusions, string $index): Builder
    {
        $query = DB::table('releases');
        if ($this->isMariaDb()) {
            $query->forceIndex($index);
        }
        $query->where('releases.category_band', Category::TV_ROOT)
            ->whereRaw('releases.passwordstatus '.$this->releases->showPasswords());
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
        if ($filters->shows->any()) {
            $query->whereIn('releases.videos_id', $this->wall->matchingShows($filters->shows)->select('v.id'));
        }

        return $query;
    }

    /**
     * A language is an EXISTS; Unknown alone is an anti-join (MariaDB copies the whole table for
     * a NOT EXISTS on every query); both together are either test, as NOT EXISTS, since the
     * anti-join would repeat a release once per language.
     */
    private function whereAudio(Builder $query, TvReleaseFilters $filters): void
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

    private function isMariaDb(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
}
