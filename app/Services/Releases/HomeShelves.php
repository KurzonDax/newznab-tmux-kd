<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\AdultReleaseRow;
use App\Data\GenericReleaseRow;
use App\Data\HomeShelfTile;
use App\Enums\BrowseRoot;
use App\Enums\HomeShelf;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The reads of the home page's shelves (docs/proposals/home-redesign/SPEC.md 3 and 7, DATA-NOTES.md
 * 3 and 5). Every read is bounded: the counts and the TV / Movies groupings by an `adddate` window
 * on the band, a section's rail by its newest 60, the Following reads by the user's followed
 * titles. Nothing is read per tile or per row, and the number of statements of a page depends only
 * on which shelves it shows. The password rule and the user's category exclusions apply to every
 * tile, row and count; the Following shelf also applies each follow's own category list.
 *
 * @phpstan-type ShelfView array{shelf: HomeShelf, count: string, tiles: list<HomeShelfTile>}
 * @phpstan-type PanelView array{title: string, count: ?string, rows: list<GenericReleaseRow>, more: array{label: string, url: string}|null}
 */
final class HomeShelves
{
    /** A rail's tiles (the Following rail holds every followed title) and a title panel's rows. */
    public const int RAIL = 60;

    private const array TILE_COLUMNS = ['id', 'guid', 'searchname', 'display_name', 'categories_id', 'size', 'adddate', 'haspreview', 'jpgstatus'];

    private ?string $passwordRule = null;

    /** @var Collection<int|string, mixed>|null */
    private ?Collection $categoryTitles = null;

    public function __construct(
        private readonly ReleaseBrowseService $releases,
        private readonly GenericReleaseRows $rows,
        private readonly WatchlistService $watchlist,
    ) {}

    /**
     * The page's shelves with their count lines and tiles.
     *
     * @param  list<HomeShelf>  $shelves  the ticked shelves the user may view, in the user's order
     * @param  list<HomeShelf>  $viewable  every shelf the user may view
     * @param  list<int>  $exclusions  the user's hidden category ids
     * @param  int|null  $lastVisit  the previous visit the "new" badges compare against; null counts nothing as new
     * @return list<ShelfView>
     */
    public function shelves(array $shelves, array $viewable, User $user, array $exclusions, ?int $lastVisit): array
    {
        $now = self::now();
        $sections = array_values(array_filter($shelves, static fn (HomeShelf $shelf): bool => $shelf !== HomeShelf::Following));
        $bands = array_map(static fn (HomeShelf $shelf): int => (int) $shelf->band(), $sections);
        $today = $sections === [] ? [] : $this->arrivals($bands, $now->subDay(), $exclusions);
        $week = $sections === [] ? [] : $this->arrivals($bands, $now->subDays(7), $exclusions);
        $views = [];
        foreach ($shelves as $shelf) {
            // a section's arrivals: "N today", else "N this week", else "none this week"; never "0 today" (Following has no band)
            $band = $shelf->band();
            $arrived = match (true) {
                $band === null => '',
                ($today[$band] ?? 0) > 0 => number_format($today[$band]).' today',
                ($week[$band] ?? 0) > 0 => number_format($week[$band]).' this week',
                default => 'none this week',
            };
            $views[] = match ($shelf) {
                HomeShelf::Following => $this->following($user, $viewable, $exclusions, $lastVisit, $now),
                HomeShelf::Tv => $this->shows($arrived, $exclusions, $now),
                HomeShelf::Movies => $this->films($arrived, $exclusions, $now),
                HomeShelf::Audio => ['shelf' => $shelf, 'count' => $arrived, 'tiles' => $this->albums($exclusions, $now)],
                default => ['shelf' => $shelf, 'count' => $arrived, 'tiles' => $this->cards($shelf, $exclusions, $now)],
            };
        }

        return $views;
    }

    /**
     * The panel of one opened tile, read under the rules of the shelf the tile is on; null when the
     * shelf has no such tile for this user.
     *
     * @param  list<HomeShelf>  $viewable
     * @param  list<int>  $exclusions
     * @return PanelView|null
     */
    public function panel(HomeShelf $shelf, string $kind, int $id, array $viewable, User $user, array $exclusions): ?array
    {
        if (! in_array($shelf, $viewable, true) || ! in_array($kind, $shelf->tileKinds(), true) || $id < 1) {
            return null;
        }

        return match ($kind) {
            'show' => $this->showPanel($shelf, $id, $viewable, $user, $exclusions),
            'film' => $this->filmPanel($shelf, $id, $viewable, $user, $exclusions),
            'album' => $this->albumPanel($id, $exclusions),
            default => $this->releasePanel($shelf, $id, $exclusions),
        };
    }

    /**
     * The visible releases added since `$since`, per band: counted per category on the band's
     * added index and summed here without the user's hidden categories.
     *
     * @param  list<int>  $bands
     * @param  list<int>  $exclusions
     * @return array<int, int> band => releases
     */
    private function arrivals(array $bands, CarbonImmutable $since, array $exclusions): array
    {
        $totals = [];
        foreach ($this->visible()->whereIn('category_band', $bands)->where('adddate', '>=', self::sql($since))
            ->groupBy('categories_id')->get(['categories_id', DB::raw('COUNT(*) AS total')]) as $row) {
            $category = (int) $row->categories_id;
            if (! in_array($category, $exclusions, true)) {
                $band = intdiv($category, 1000) * 1000;
                $totals[$band] = ($totals[$band] ?? 0) + (int) $row->total;
            }
        }

        return $totals;
    }

    /**
     * TV: the shows with a release added in the last 24 hours. The count names every such show; the
     * rail holds the 60 with the newest posting.
     *
     * @param  list<int>  $exclusions
     * @return ShelfView
     */
    private function shows(string $arrived, array $exclusions, CarbonImmutable $now): array
    {
        $titles = $this->arrivedTitles(HomeShelf::Tv, 'videos_id', $now->subDay(), $exclusions);
        $rail = array_slice($titles, 0, self::RAIL, true);
        $shows = DB::table('videos')->whereIn('id', array_keys($rail))->pluck('title', 'id');
        $tiles = [];
        foreach ($rail as $id => $title) {
            if ($shows->has($id)) {
                $tiles[] = new HomeShelfTile('show', $id, (string) $shows->get($id), self::counted($title['total'], 'episode').' today', getImageAssetUrl('tvshows', (string) $id));
            }
        }

        return ['shelf' => HomeShelf::Tv, 'count' => $arrived.' · '.self::counted(count($titles), 'show').' with new episodes', 'tiles' => $tiles];
    }

    /**
     * Movies: the films with a release added in the last 7 days, as the shows above.
     *
     * @param  list<int>  $exclusions
     * @return ShelfView
     */
    private function films(string $arrived, array $exclusions, CarbonImmutable $now): array
    {
        $titles = $this->arrivedTitles(HomeShelf::Movies, 'movieinfo_id', $now->subDays(7), $exclusions);
        $rail = array_slice($titles, 0, self::RAIL, true);
        $films = DB::table('movieinfo')->whereIn('id', array_keys($rail))->get(['id', 'imdbid', 'title', 'year'])->keyBy('id');
        $tiles = [];
        foreach ($rail as $id => $title) {
            $film = $films->get($id);
            if ($film !== null) {
                $year = self::year($film->year);
                $tiles[] = new HomeShelfTile('film', $id, (string) $film->title.($year === '' ? '' : ' ('.$year.')'),
                    self::counted($title['total'], 'release').' · '.self::ago($title['newest'], $now), self::filmPoster((string) $film->imdbid), $year);
            }
        }

        return ['shelf' => HomeShelf::Movies, 'count' => $arrived.' · '.self::counted(count($titles), 'film').' this week', 'tiles' => $tiles];
    }

    /**
     * The titles (shows or films) of a band with a visible release added since `$since`, grouped
     * per title and category without a limit, the user's hidden categories dropped here: each
     * title's releases in the window and its newest posting, the newest posting first.
     *
     * @param  list<int>  $exclusions
     * @return array<int, array{total: int, newest: string}>
     */
    private function arrivedTitles(HomeShelf $shelf, string $key, CarbonImmutable $since, array $exclusions): array
    {
        $titles = [];
        foreach ($this->visible()->where('category_band', $shelf->band())->where('adddate', '>=', self::sql($since))->where($key, '>', 0)
            ->groupBy($key, 'categories_id')->get([$key, 'categories_id', DB::raw('COUNT(*) AS total'), DB::raw('MAX(postdate) AS newest')]) as $row) {
            if (in_array((int) $row->categories_id, $exclusions, true)) {
                continue;
            }
            $id = (int) $row->{$key};
            $titles[$id] = [
                'total' => ($titles[$id]['total'] ?? 0) + (int) $row->total,
                'newest' => max($titles[$id]['newest'] ?? '', (string) $row->newest),
            ];
        }
        uksort($titles, static fn (int $a, int $b): int => [$titles[$b]['newest'], $b] <=> [$titles[$a]['newest'], $a]);

        return $titles;
    }

    /**
     * Audio: the newest 60 releases as album tiles, one per performer + album.
     *
     * @param  list<int>  $exclusions
     * @return list<HomeShelfTile>
     */
    private function albums(array $exclusions, CarbonImmutable $now): array
    {
        $tiles = [];
        foreach ($this->albumGroups($exclusions) as $group) {
            $first = $group['releases'][0];
            $tiles[] = new HomeShelfTile('album', (int) $first->id, $group['album'] === '' ? release_display_name($first) : $group['album'],
                self::ago($first->adddate, $now), label: $group['performer']);
        }

        return $tiles;
    }

    /**
     * The newest 60 Audio releases grouped by performer + album (the album performer, else the
     * performer, as the Audio list reads it); a release with no tags, or whose tags name no
     * album, is a group of its own. Groups and their releases keep the newest-first order.
     *
     * @param  list<int>  $exclusions
     * @return list<array{performer: string, album: string, releases: list<object>}>
     */
    private function albumGroups(array $exclusions): array
    {
        $releases = $this->newest(HomeShelf::Audio, $exclusions);
        $tags = DB::table('release_audio_tags')->whereIn('releases_id', array_map(static fn (object $release): int => (int) $release->id, $releases))
            ->get(['releases_id', 'album', 'album_performer', 'performer'])->keyBy('releases_id');
        $groups = [];
        foreach ($releases as $release) {
            $tag = $tags->get((int) $release->id);
            $performer = $tag === null ? '' : (self::text($tag->album_performer) ?? self::text($tag->performer) ?? '');
            $album = $tag === null ? '' : (self::text($tag->album) ?? '');
            $key = $album === '' ? 'release:'.$release->id : 'album:'.$performer."\0".$album;
            $groups[$key] ??= ['performer' => $performer, 'album' => $album, 'releases' => []];
            $groups[$key]['releases'][] = $release;
        }

        return array_values($groups);
    }

    /**
     * Books, Console, PC and Other: the newest 60 as release cards; Adult: as picture tiles with
     * the Adult list's picture rule.
     *
     * @param  list<int>  $exclusions
     * @return list<HomeShelfTile>
     */
    private function cards(HomeShelf $shelf, array $exclusions, CarbonImmutable $now): array
    {
        $titles = $this->categoryTitles();
        $tiles = [];
        foreach ($this->newest($shelf, $exclusions) as $release) {
            $category = (string) ($titles[(int) $release->categories_id] ?? '');
            $what = ReleaseRowFacts::size((float) $release->size).' · '.self::ago($release->adddate, $now);
            if ($shelf !== HomeShelf::Adult) {
                $tiles[] = new HomeShelfTile('rel', (int) $release->id, release_display_name($release), $what, label: $category);

                continue;
            }
            $guid = (string) $release->guid;
            $picture = AdultReleaseRow::pictureOf(
                (int) $release->haspreview === 1 ? ['thumb' => getImageAssetUrl('preview', $guid.'_thumb')] : null,
                (int) $release->jpgstatus === 1 ? ['thumb' => getImageAssetUrl('sample', $guid.'_thumb')] : null,
            );
            $tiles[] = new HomeShelfTile('pic', (int) $release->id, release_display_name($release), ($category === '' ? '' : $category.' · ').$what, $picture['url'] ?? null);
        }

        return $tiles;
    }

    /**
     * A section's newest 60 visible releases by posting date, the read its list makes for its first
     * page: the ids from the band's posted index, then those rows by key.
     *
     * @param  list<int>  $exclusions
     * @return list<object>
     */
    private function newest(HomeShelf $shelf, array $exclusions): array
    {
        $ids = $this->visible()->where('category_band', $shelf->band())->whereNotIn('categories_id', $exclusions)
            ->orderByDesc('postdate')->orderByDesc('id')->limit(self::RAIL)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $rows = DB::table('releases')->whereIn('id', $ids)->get(self::TILE_COLUMNS)->keyBy('id');

        return array_values(array_filter(array_map(static fn (int $id): ?object => $rows->get($id), $ids)));
    }

    /**
     * Following: every followed show and film of the sections the user may view. One statement per
     * kind reads each title with its newest release's added time and the number of its releases
     * added after the last visit, both under the follow's own category list. Newest release first,
     * then the titles with no release.
     *
     * @param  list<HomeShelf>  $viewable
     * @param  list<int>  $exclusions
     * @return ShelfView
     */
    private function following(User $user, array $viewable, array $exclusions, ?int $lastVisit, CarbonImmutable $now): array
    {
        $titles = [];
        if (in_array(HomeShelf::Tv, $viewable, true)) {
            foreach ($this->followed(BrowseRoot::Tv, $user, $exclusions, $lastVisit)->join('videos as t', 't.id', '=', 'f.videos_id')
                ->addSelect(['t.id', 't.title'])->get() as $show) {
                $titles['show:'.$show->id] ??= ['kind' => 'show', 'row' => $show, 'art' => getImageAssetUrl('tvshows', (string) $show->id), 'label' => ''];
            }
        }
        if (in_array(HomeShelf::Movies, $viewable, true)) {
            foreach ($this->followed(BrowseRoot::Movies, $user, $exclusions, $lastVisit)->join('movieinfo as t', 't.imdbid', '=', 'f.imdbid')
                ->addSelect(['t.id', 't.title', 't.year', 't.imdbid'])->get() as $film) {
                $titles['film:'.$film->id] ??= ['kind' => 'film', 'row' => $film, 'art' => self::filmPoster((string) $film->imdbid), 'label' => self::year($film->year)];
            }
        }
        $titles = array_values($titles);
        usort($titles, static fn (array $a, array $b): int => [(string) $b['row']->newest, mb_strtolower((string) $a['row']->title)] <=> [(string) $a['row']->newest, mb_strtolower((string) $b['row']->title)]);
        $tiles = array_map(static function (array $title) use ($now): HomeShelfTile {
            $fresh = (int) $title['row']->fresh;

            return new HomeShelfTile($title['kind'], (int) $title['row']->id, (string) $title['row']->title,
                $title['row']->newest === null ? 'no releases yet' : self::ago((string) $title['row']->newest, $now),
                $title['art'], $title['label'], $fresh > 0 ? number_format($fresh).' new' : '', $fresh === 0);
        }, $titles);
        $new = count(array_filter($tiles, static fn (HomeShelfTile $tile): bool => $tile->badge !== ''));

        return ['shelf' => HomeShelf::Following, 'count' => $tiles === [] ? '' : number_format($new).' of '.number_format(count($tiles)).' with something new', 'tiles' => $tiles];
    }

    /**
     * The user's follow rows of a root (`f`), each with `newest` (when its newest visible release
     * inside the follow's category list was added) and `fresh` (how many of them were added after
     * the last visit).
     *
     * @param  list<int>  $exclusions
     */
    private function followed(BrowseRoot $root, User $user, array $exclusions, ?int $lastVisit): Builder
    {
        $key = $root === BrowseRoot::Movies ? 'imdbid' : 'videos_id';
        $membership = DB::getDriverName() === 'sqlite'
            ? "INSTR('|' || f.categories || '|', '|' || %s || '|') > 0"
            : "LOCATE(CONCAT('|', %s, '|'), CONCAT('|', f.categories, '|')) > 0";
        $releases = fn (): Builder => $this->visible('r')->whereColumn('r.'.$key, 'f.'.$key)->where('r.category_band', $root->categoryId())
            ->whereNotIn('r.categories_id', $exclusions)
            ->where(static function (Builder $list) use ($membership, $root): void {
                // the follow's list: none stored, or the root's own id, means every category of the root (ReleaseBrowserQuery)
                $list->whereNull('f.categories')->orWhereIn('f.categories', ['', 'NULL'])
                    ->orWhereRaw(sprintf($membership, '?'), [$root->categoryId()])->orWhereRaw(sprintf($membership, 'r.categories_id'));
            });
        $query = DB::table(($root === BrowseRoot::Movies ? 'user_movies' : 'user_series').' as f')->where('f.users_id', $user->id)
            ->selectSub($releases()->select('r.adddate')->orderByDesc('r.adddate')->orderByDesc('r.id')->limit(1), 'newest');

        return $lastVisit === null
            ? $query->selectRaw('0 AS fresh')
            : $query->selectSub($releases()->selectRaw('COUNT(*)')->where('r.adddate', '>', self::sql(CarbonImmutable::createFromTimestamp($lastVisit, config('app.timezone', 'UTC')))), 'fresh');
    }

    /**
     * A show's panel: its newest releases, newest added first, up to 60; in Following only those
     * inside the follow's category list.
     *
     * @param  list<HomeShelf>  $viewable
     * @param  list<int>  $exclusions
     * @return PanelView|null
     */
    private function showPanel(HomeShelf $shelf, int $id, array $viewable, User $user, array $exclusions): ?array
    {
        $title = DB::table('videos')->where('id', $id)->value('title');
        $releases = $this->titleReleases(BrowseRoot::Tv, 'videos_id', $id, $exclusions);
        if ($title === null || ($shelf === HomeShelf::Following && ! $this->withinFollow($releases, BrowseRoot::Tv, (string) $id, $viewable, $user))) {
            return null;
        }

        return $this->titlePanel((string) $title, $releases, 'All episodes and seasons', route('tv.show', ['videosId' => $id]));
    }

    /**
     * A film's panel, as a show's. The Movies rail groups by the film (`movieinfo_id`); a follow is
     * kept by `imdbid`, so the Following panel reads the film's releases by it.
     *
     * @param  list<HomeShelf>  $viewable
     * @param  list<int>  $exclusions
     * @return PanelView|null
     */
    private function filmPanel(HomeShelf $shelf, int $id, array $viewable, User $user, array $exclusions): ?array
    {
        $film = DB::table('movieinfo')->where('id', $id)->first(['title', 'imdbid']);
        if ($film === null) {
            return null;
        }
        if ($shelf === HomeShelf::Following) {
            $releases = $this->titleReleases(BrowseRoot::Movies, 'imdbid', (string) $film->imdbid, $exclusions);
            if (! $this->withinFollow($releases, BrowseRoot::Movies, (string) $film->imdbid, $viewable, $user)) {
                return null;
            }
        } else {
            $releases = $this->titleReleases(BrowseRoot::Movies, 'movieinfo_id', $id, $exclusions);
        }

        return $this->titlePanel((string) $film->title, $releases, 'All releases of this film', route('movies.film', ['movieinfoId' => $id]));
    }

    /**
     * The visible releases of one title, newest added first, not yet limited.
     *
     * @param  list<int>  $exclusions
     */
    private function titleReleases(BrowseRoot $root, string $key, int|string $id, array $exclusions): Builder
    {
        return $this->visible()->where($key, $id)->where('category_band', $root->categoryId())->whereNotIn('categories_id', $exclusions)
            ->orderByDesc('adddate')->orderByDesc('id');
    }

    /**
     * Narrows a followed title's releases to the follow's category list; false when the user does
     * not follow the title or may not view its section.
     *
     * @param  list<HomeShelf>  $viewable
     */
    private function withinFollow(Builder $releases, BrowseRoot $root, string $id, array $viewable, User $user): bool
    {
        $movies = $root === BrowseRoot::Movies;
        $follow = $this->watchlist->subscriptions($root, $user)->where($movies ? 'imdbid' : 'videos_id', $id)->first();
        if ($follow === null || ! in_array($movies ? HomeShelf::Movies : HomeShelf::Tv, $viewable, true)) {
            return false;
        }
        $releases->whereIn('categories_id', $this->watchlist->selected($follow->categories, $root, array_keys($this->watchlist->categories($root, $user))));

        return true;
    }

    /** @return PanelView */
    private function titlePanel(string $title, Builder $releases, string $label, string $url): array
    {
        $rows = $this->rows->load($releases->limit(self::RAIL)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(), true);

        return [
            'title' => $title,
            'count' => match (count($rows)) {
                0 => null,
                1 => 'newest release',
                default => count($rows).' newest releases',
            },
            'rows' => $rows,
            'more' => ['label' => $label, 'url' => $url],
        ];
    }

    /**
     * An album tile's panel: the releases grouped into that tile, newest first.
     *
     * @param  list<int>  $exclusions
     * @return PanelView|null
     */
    private function albumPanel(int $id, array $exclusions): ?array
    {
        foreach ($this->albumGroups($exclusions) as $group) {
            $ids = array_map(static fn (object $release): int => (int) $release->id, $group['releases']);
            if (in_array($id, $ids, true)) {
                $title = $group['album'] === '' ? release_display_name($group['releases'][0]) : implode(' · ', array_filter([$group['performer'], $group['album']]));

                return ['title' => $title, 'count' => null, 'rows' => $this->rows->load($ids, true), 'more' => null];
            }
        }

        // no longer among the newest 60: the release alone
        return $this->releasePanel(HomeShelf::Audio, $id, $exclusions);
    }

    /**
     * A release card's or an Adult tile's panel: that one row.
     *
     * @param  list<int>  $exclusions
     * @return PanelView|null
     */
    private function releasePanel(HomeShelf $shelf, int $id, array $exclusions): ?array
    {
        $visible = $this->visible()->where('id', $id)->where('category_band', $shelf->band())->whereNotIn('categories_id', $exclusions)->exists();
        $rows = $visible ? $this->rows->load([$id], true) : [];

        return $rows === [] ? null : ['title' => $rows[0]->name, 'count' => null, 'rows' => $rows, 'more' => null];
    }

    /** The releases the password rule lets everyone see (ReleaseBrowseService::showPasswords). */
    private function visible(?string $alias = null): Builder
    {
        $this->passwordRule ??= $this->releases->showPasswords();

        return DB::table($alias === null ? 'releases' : 'releases as '.$alias)->whereRaw(($alias === null ? '' : $alias.'.').'passwordstatus '.$this->passwordRule);
    }

    /** @return Collection<int|string, mixed> every sub-category's title by id, read once */
    private function categoryTitles(): Collection
    {
        return $this->categoryTitles ??= DB::table('categories')->pluck('title', 'id');
    }

    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone', 'UTC'));
    }

    private static function sql(CarbonImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s');
    }

    /** "1 episode", "17 episodes", with thousands separators. */
    private static function counted(int $number, string $noun): string
    {
        return number_format($number).' '.$noun.($number === 1 ? '' : 's');
    }

    /**
     * How long ago, as the prototype writes it: minutes, hours, then days up to a week, then the date.
     */
    private static function ago(?string $value, CarbonImmutable $now): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $minutes = max(1, (int) round(($now->getTimestamp() - CarbonImmutable::parse($value, config('app.timezone', 'UTC'))->getTimestamp()) / 60));
        $days = (int) round($minutes / 1440);

        return match (true) {
            $minutes < 60 => $minutes.' min ago',
            $minutes < 1440 => (int) round($minutes / 60).' hr ago',
            $days < 8 => $days.($days === 1 ? ' day ago' : ' days ago'),
            default => userDate($value, 'M j, Y'),
        };
    }

    /** A film's cover as the Films wall builds it (MovieFilmWall); null without one. */
    private static function filmPoster(string $imdbId): ?string
    {
        return $imdbId === '' ? null : getImageAssetUrl('movies', $imdbId.'-cover');
    }

    private static function year(mixed $year): string
    {
        return preg_match('/^\d{4}$/', (string) $year) === 1 ? (string) $year : '';
    }

    /** A tag value as written, or null when it is missing or empty. */
    private static function text(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : (string) $value;
    }
}
