<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\TvShowFilters;
use App\Data\TvShowTile;
use App\Models\Category;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Locale;

/**
 * The TV shows wall's queries (docs/proposals/tv-redesign/DATA-CONTRACT.md section 4). A show
 * is listed when it has at least one TV release the user may see (today's browse visibility:
 * the password setting and the user's excluded categories). "Newest releases first" and
 * "Newest to the site first" take their key from the index-only group-by on
 * ix_releases_videos_posted / _added, which ignores visibility: a show's position can come
 * from a release the user cannot see (accepted 2026-09-25). Nothing per show is stored.
 */
final class TvShowWall
{
    /** A show's premiere: TMDB's first air date, else the site's start date. */
    private const string PREMIERED = 'COALESCE(t.premiered, v.started)';

    public function __construct(private readonly ReleaseBrowseService $releases) {}

    /**
     * The six menus' options for the shows the user may see, keyed by URL key, each value => text in menu order.
     * Built from one pass over the visible shows (about 30 ms at catalogue size) and cached.
     *
     * @param  list<int>  $exclusions
     * @return array{genre: array<int, string>, decade: array<int, string>, language: array<string, string>, network: array<int, string>, rating: array<string, string>, status: array<string, string>}
     */
    public function options(array $exclusions): array
    {
        return Cache::remember($this->cacheKey('tv_shows_options', '', $exclusions), $this->ttl(), function () use ($exclusions): array {
            $shows = $this->shows(new TvShowFilters, $exclusions)
                ->selectRaw('v.id, t.original_language, t.networks_id, t.content_rating_us, '.self::PREMIERED.' AS premiered')->get();
            $listed = $decades = $languages = $networks = $ratings = [];
            foreach ($shows as $show) {
                $listed[(int) $show->id] = true;
                $decade = intdiv((int) substr((string) $show->premiered, 0, 4), 10) * 10;
                if ($decade >= 1900) {
                    $decades[$decade] = $decade.'s';
                }
                if ((string) $show->original_language !== '') {
                    $languages[(string) $show->original_language] = ($languages[(string) $show->original_language] ?? 0) + 1;
                }
                if ($show->networks_id !== null) {
                    $networks[(int) $show->networks_id] = true;
                }
                $ratings[(string) $show->content_rating_us] = true;
            }
            krsort($decades);

            $genres = [];
            foreach (DB::table('video_genres as vg')->join('genres as g', 'g.id', '=', 'vg.genres_id')->where('g.type', Category::TV_ROOT)
                ->orderBy('g.title')->get(['vg.videos_id', 'g.id', 'g.title']) as $row) {
                if (isset($listed[(int) $row->videos_id])) {
                    $genres[(int) $row->id] = (string) $row->title;
                }
            }

            $names = [];
            foreach ($languages as $code => $count) {
                $names[] = ['code' => (string) $code, 'name' => self::languageName((string) $code), 'shows' => $count];
            }
            usort($names, static fn (array $a, array $b): int => [$b['shows'], $a['name']] <=> [$a['shows'], $b['name']]);

            $networkNames = $networks === [] ? [] : DB::table('networks')->whereIn('id', array_keys($networks))->pluck('name', 'id')
                ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])->all();
            uasort($networkNames, strnatcasecmp(...));

            $ratingOptions = array_values(array_filter(TvShowFilters::RATINGS, static fn (string $rating): bool => isset($ratings[$rating])));

            return [
                'genre' => $genres,
                'decade' => $decades,
                'language' => array_column($names, 'name', 'code'),
                'network' => $networkNames,
                'rating' => array_combine($ratingOptions, $ratingOptions),
                'status' => TvShowFilters::STATUS_LABELS,
            ];
        });
    }

    /** @param list<int> $exclusions */
    public function count(TvShowFilters $filters, array $exclusions): int
    {
        return (int) Cache::remember($this->cacheKey('tv_shows_count', $filters->countKey(), $exclusions), $this->ttl(),
            fn (): int => $this->shows($filters, $exclusions)->count());
    }

    /**
     * The ids of the requested page in display order.
     *
     * @param  list<int>  $exclusions
     * @return list<int>
     */
    public function pageIds(TvShowFilters $filters, array $exclusions, int $total): array
    {
        $offset = ($filters->page - 1) * TvShowFilters::PER_PAGE;
        if ($offset >= $total) {
            return [];
        }
        $query = $this->shows($filters, $exclusions)->select('v.id');
        match ($filters->sort) {
            'recent' => $query->joinSub($this->releaseDates('MAX(postdate)', 'ix_releases_videos_posted'), 'g', 'g.videos_id', '=', 'v.id')
                ->orderByDesc('g.sort_key')->orderByDesc('v.id'),
            'newsite' => $query->joinSub($this->releaseDates('MIN(adddate)', 'ix_releases_videos_added'), 'g', 'g.videos_id', '=', 'v.id')
                ->orderByDesc('g.sort_key')->orderByDesc('v.id'),
            'prem' => $query->orderByRaw(self::PREMIERED.' DESC')->orderByDesc('v.id'),
            default => $query->orderBy('v.title')->orderBy('v.id'),
        };

        return $query->offset($offset)->limit(TvShowFilters::PER_PAGE)->pluck('v.id')
            ->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * @param  list<int>  $ids  in display order
     * @return list<TvShowTile>
     */
    public function tiles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $shows = DB::table('videos as v')->leftJoin('tv_info as t', 't.videos_id', '=', 'v.id')->whereIn('v.id', $ids)
            ->get(['v.id', 'v.title', 'v.started', 't.premiered', 't.original_language', 't.content_rating_us'])->keyBy('id');
        $genres = $this->genreTitles($ids);
        $tiles = [];
        foreach ($ids as $id) {
            $show = $shows->get($id);
            if ($show === null) {
                continue;
            }
            $rating = (string) $show->content_rating_us;
            $tiles[] = new TvShowTile(
                id: $id,
                title: (string) $show->title,
                url: url('/tv/show/'.$id),
                poster: getImageAssetUrl('tvshows', (string) $id),
                line1: implode(' · ', array_filter([(string) self::year($show->premiered, $show->started), implode(', ', array_slice($genres[$id] ?? [], 0, 2))])),
                line2: implode(' · ', array_filter([self::languageName((string) $show->original_language), $rating === 'NR' ? '' : $rating])),
            );
        }

        return $tiles;
    }

    /**
     * TV genre titles per show, alphabetical (the link table keeps no order).
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    public function genreTitles(array $ids): array
    {
        $titles = [];
        foreach (DB::table('video_genres as vg')->join('genres as g', 'g.id', '=', 'vg.genres_id')->where('g.type', Category::TV_ROOT)
            ->whereIn('vg.videos_id', $ids)->orderBy('g.title')->get(['vg.videos_id', 'g.title']) as $row) {
            $titles[(int) $row->videos_id][] = (string) $row->title;
        }

        return $titles;
    }

    public function personName(int $id): ?string
    {
        $name = DB::table('people')->where('id', $id)->value('name');

        return is_string($name) ? $name : null;
    }

    /**
     * Keeps the shows that have at least one TV release the user may see. Written as a
     * scalar subquery, not EXISTS: MariaDB turns an EXISTS into a semi-join that reads every
     * TV release (about 90 ms at catalogue size); the per-show probe costs about 25 ms for
     * all 5,600 shows and stops early on a first page.
     *
     * @param  list<int>  $exclusions
     */
    public function whereVisible(Builder $query, array $exclusions, string $show = 'v.id'): Builder
    {
        $password = $this->releases->showPasswords();

        return $query->where(static function (Builder $probe) use ($exclusions, $password, $show): void {
            $probe->selectRaw('1')->from('releases as r')->whereColumn('r.videos_id', $show)
                ->where('r.category_band', Category::TV_ROOT)->whereRaw('r.passwordstatus '.$password);
            if ($exclusions !== []) {
                $probe->whereNotIn('r.categories_id', $exclusions);
            }
            $probe->limit(1);
        }, '=', 1);
    }

    /** The premiere year shown on tiles and in the search: TMDB's first air date, else the site's start date. */
    public static function year(?string $premiered, ?string $started): ?int
    {
        $year = (int) substr((string) ($premiered ?? $started), 0, 4);

        return $year >= 1900 ? $year : null;
    }

    /** "ko" → "Korean"; a code ICU does not know stays as it is. */
    public static function languageName(string $code): string
    {
        if ($code === '') {
            return '';
        }
        $name = Locale::getDisplayLanguage($code, 'en');

        return $name === '' ? $code : $name;
    }

    /** @param list<int> $exclusions */
    private function shows(TvShowFilters $filters, array $exclusions): Builder
    {
        $query = $this->whereVisible(DB::table('videos as v')->leftJoin('tv_info as t', 't.videos_id', '=', 'v.id')->where('v.type', 0), $exclusions);
        if ($filters->genres !== []) {
            $query->whereExists(static fn (Builder $exists) => $exists->selectRaw('1')->from('video_genres as vg')
                ->whereColumn('vg.videos_id', 'v.id')->whereIn('vg.genres_id', $filters->genres));
        }
        if ($filters->person !== null) {
            $query->whereExists(static fn (Builder $exists) => $exists->selectRaw('1')->from('video_people as vp')
                ->whereColumn('vp.videos_id', 'v.id')->where('vp.people_id', $filters->person));
        }
        if ($filters->decades !== []) {
            $query->where(static function (Builder $any) use ($filters): void {
                foreach ($filters->decades as $decade) {
                    $any->orWhereRaw(self::PREMIERED.' >= ? AND '.self::PREMIERED.' < ?', [$decade.'-01-01', ($decade + 10).'-01-01']);
                }
            });
        }
        if ($filters->languages !== []) {
            $query->whereIn('t.original_language', $filters->languages);
        }
        if ($filters->networks !== []) {
            $query->whereIn('t.networks_id', $filters->networks);
        }
        if ($filters->ratings !== []) {
            $query->whereIn('t.content_rating_us', $filters->ratings);
        }
        if ($filters->statuses !== []) {
            $query->whereIn('t.status', $filters->statusValues());
        }

        return $query;
    }

    /** One sort key per show from releases, answered from the (videos_id, date) index alone. */
    private function releaseDates(string $aggregate, string $index): Builder
    {
        $query = DB::table('releases');
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->forceIndex($index);
        }

        return $query->select('videos_id')->selectRaw($aggregate.' AS sort_key')->where('videos_id', '>', 0)->groupBy('videos_id');
    }

    /** @param list<int> $exclusions */
    private function cacheKey(string $prefix, string $filters, array $exclusions): string
    {
        sort($exclusions);

        return $prefix.':'.ReleaseBrowseService::cacheVersion().':'.md5($filters.'|'.implode(',', $exclusions).'|'.$this->releases->showPasswords());
    }

    private function ttl(): \DateTimeInterface
    {
        return now()->addMinutes(max(1, (int) config('nntmux.cache_expiry_short', 5)) * 2);
    }
}
