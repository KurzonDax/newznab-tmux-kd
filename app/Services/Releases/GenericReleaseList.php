<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\GenericListContext;
use App\Data\GenericReleaseFilters;
use App\Data\ReleaseListFilters;
use App\Enums\BrowseRoot;
use App\Models\Category;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The generic release lists' queries (docs/proposals/generic-release-lists/SPEC.md 5,
 * DATA-NOTES.md 3): All releases, a group's releases, a poster's posts and the Other category,
 * read from `releases` with today's conditions (ReleaseBrowserQuery): the site's password setting,
 * the user's excluded categories, the group by name, the poster identity byte for byte, the
 * Following scope, the completion threshold, the Category filter (root bands, Exclude Other, or
 * Misc / Hashed on Other) and the name search. These lists have no band index: the count is live
 * and a page is read by offset in the index MariaDB picks. The Category menu is probed per root
 * and cached for an hour under every predicate the probes apply, so one viewer's exclusions never
 * shape another's menu.
 */
final class GenericReleaseList
{
    /** How long the Category menu's presence probes are kept. */
    public const int MENU_SECONDS = 3600;

    /** The name the search reads and Name: A to Z sorts by: the display name, or the search name when it is empty. */
    private const string RELEASE_NAME = "COALESCE(NULLIF(TRIM(releases.display_name), ''), releases.searchname)";

    public function __construct(private readonly ReleaseBrowseService $releases) {}

    /**
     * The live count of the list (a cached count would outlive a blacklist sweep, SPEC 5.8).
     *
     * @param  list<int>  $exclusions
     */
    public function count(GenericReleaseFilters $filters, array $exclusions, int $userId): int
    {
        return $this->visible($filters, $exclusions, $userId)->count();
    }

    /**
     * The ids of the requested page in display order: Posted / Added newest or oldest (the date,
     * then the id), or Name: A to Z (the display-name expression, then the id).
     *
     * @param  list<int>  $exclusions
     * @return list<int>
     */
    public function pageIds(GenericReleaseFilters $filters, array $exclusions, int $userId, int $total): array
    {
        $offset = ($filters->page - 1) * ReleaseListFilters::PER_PAGE;
        $limit = min(ReleaseListFilters::PER_PAGE, $total - $offset);
        if ($limit <= 0) {
            return [];
        }
        $query = $this->visible($filters, $exclusions, $userId);
        if ($filters->sortsByName()) {
            $query->orderByRaw(self::RELEASE_NAME.' asc')->orderBy('releases.id');
        } else {
            $direction = $filters->ascending() ? 'asc' : 'desc';
            $query->orderBy($filters->sortsByAdded() ? 'releases.adddate' : 'releases.postdate', $direction)->orderBy('releases.id', $direction);
        }

        return $query->offset($offset)->limit($limit)->pluck('releases.id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * The Category menu (SPEC 5.2): on the Other list Misc and Hashed, in that order, the user's
     * excluded ones left out; on the All, group and poster lists the roots in the header bar's
     * order, labelled by the header's label, each kept while the list has a release in it for
     * this viewer (one EXISTS probe per root inside the list's conditions) and the user's excluded
     * roots left out. The probes are cached for MENU_SECONDS under the context, the password
     * setting, the normalized exclusions and the Following scope's user.
     *
     * @param  list<int>  $exclusions
     * @return array<int, string> id => label, in menu order
     */
    public function categoryMenu(GenericListContext $context, array $exclusions, int $userId): array
    {
        $exclusions = array_values(array_unique(array_map('intval', $exclusions)));
        sort($exclusions);
        $visible = [];
        foreach (Category::getForMenu($exclusions) as $root) {
            $visible[(int) $root['id']] = array_column($root['categories'], 'title', 'id');
        }
        if ($context->isOther()) {
            $menu = [];
            foreach (GenericListContext::otherCategories() as $id) {
                if (isset($visible[Category::OTHER_ROOT][$id])) {
                    $menu[$id] = (string) $visible[Category::OTHER_ROOT][$id];
                }
            }

            return $menu;
        }
        $roots = [];
        foreach ([BrowseRoot::Movies, BrowseRoot::Tv, BrowseRoot::Audio, BrowseRoot::Books, BrowseRoot::Console, BrowseRoot::Games, BrowseRoot::Adult, BrowseRoot::Other] as $root) {
            if (isset($visible[$root->categoryId()])) {
                $roots[] = $root;
            }
        }
        $password = $this->releases->showPasswords();
        $identity = json_encode([$context->kind, $context->key, $context->watching ? $userId : 0, $password, $exclusions], JSON_THROW_ON_ERROR);
        $key = 'generic_releases_menu:'.ReleaseBrowseService::cacheVersion().':'.md5($identity);
        $present = Cache::remember($key, self::MENU_SECONDS, function () use ($roots, $context, $exclusions, $userId): array {
            $present = [];
            foreach ($roots as $root) {
                $query = DB::table('releases');
                $this->whereVisible($query, $exclusions);
                $this->whereContext($query, $context, $userId);
                $this->whereRoot($query, (int) $root->categoryId());
                if ($query->exists()) {
                    $present[] = $root->value;
                }
            }

            return $present;
        });
        $menu = [];
        foreach ($roots as $root) {
            if (in_array($root->value, $present, true)) {
                $menu[(int) $root->categoryId()] = $root->label();
            }
        }

        return $menu;
    }

    /**
     * How many releases a poster identity still has, whatever the viewer's exclusions, the
     * password setting or the list's filters (a blacklist sweep's confirmation, issue #1032
     * correction 1).
     */
    public function remaining(string $posterIdentity): int
    {
        return $this->wherePoster(DB::table('releases'), $posterIdentity)->count();
    }

    /**
     * The visible releases the filters keep; selects nothing yet.
     *
     * @param  list<int>  $exclusions
     */
    private function visible(GenericReleaseFilters $filters, array $exclusions, int $userId): Builder
    {
        $query = DB::table('releases');
        $this->whereVisible($query, $exclusions);
        $this->whereContext($query, $filters->context, $userId);
        $threshold = $filters->threshold();
        if ($threshold !== null) {
            $query->where('releases.completion', '>=', $threshold);
        }
        if ($filters->context->isOther()) {
            if ($filters->categories !== []) {
                $query->whereIn('releases.categories_id', $filters->categories);
            }
        } elseif ($filters->excludesOther()) {
            $query->whereNotIn('releases.categories_id', GenericListContext::otherCategories());
        } elseif ($filters->categories !== []) {
            $query->where(function (Builder $roots) use ($filters): void {
                foreach ($filters->categories as $root) {
                    $roots->orWhere(fn (Builder $band) => $this->whereRoot($band, $root));
                }
            });
        }
        if ($filters->search !== '') {
            $query->whereRaw(self::RELEASE_NAME." LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters->search).'%']);
        }

        return $query;
    }

    /**
     * Today's visibility: the password setting and the user's excluded categories.
     *
     * @param  list<int>  $exclusions
     */
    private function whereVisible(Builder $query, array $exclusions): void
    {
        $query->whereRaw('releases.passwordstatus '.$this->releases->showPasswords());
        if ($exclusions !== []) {
            $query->whereNotIn('releases.categories_id', $exclusions);
        }
    }

    /** The list's identity: the group by name, the poster byte for byte, the Other band, the Following scope. */
    private function whereContext(Builder $query, GenericListContext $context, int $userId): void
    {
        if ($context->isGroup()) {
            $query->whereIn('releases.groups_id', DB::table('usenet_groups')->select('id')->where('name', $context->key));
        } elseif ($context->isPoster()) {
            $this->wherePoster($query, $context->key);
        } elseif ($context->isOther()) {
            $query->whereIn('releases.categories_id', GenericListContext::otherCategories());
        }
        if ($context->watching) {
            $this->whereWatching($query, $userId);
        }
    }

    /** A root's band: `categories_id BETWEEN root AND root + 999`; Other is Misc and Hashed. */
    private function whereRoot(Builder $query, int $root): Builder
    {
        if ($root === Category::OTHER_ROOT) {
            return $query->whereIn('releases.categories_id', GenericListContext::otherCategories());
        }

        return $query->whereBetween('releases.categories_id', [$root, $root + 999]);
    }

    /** The poster identity byte for byte (`BINARY` on MariaDB, ReleaseBrowserQuery). */
    private function wherePoster(Builder $query, string $posterIdentity): Builder
    {
        $query->where('releases.fromname', $posterIdentity);
        if (DB::getDriverName() !== 'sqlite') {
            $query->whereRaw('BINARY releases.fromname = BINARY ?', [$posterIdentity]);
        }

        return $query;
    }

    /**
     * Today's Following scope on All releases (ReleaseBrowserQuery): the releases of the films
     * and shows the user follows, within the categories each subscription names.
     */
    private function whereWatching(Builder $query, int $userId): void
    {
        $query->where(function (Builder $watched) use ($userId): void {
            foreach ([BrowseRoot::Movies, BrowseRoot::Tv] as $root) {
                $watched->orWhere(function (Builder $titles) use ($root, $userId): void {
                    $movies = $root === BrowseRoot::Movies;
                    $key = $movies ? 'imdbid' : 'videos_id';
                    $table = $movies ? 'user_movies' : 'user_series';
                    $subscription = DB::table($table.' as watched')->selectRaw('1')
                        ->where('watched.users_id', $userId)->whereColumn('watched.'.$key, 'releases.'.$key);
                    if (Schema::hasColumn($table, 'categories')) {
                        $subscription->where(function (Builder $categories) use ($root): void {
                            $membership = DB::getDriverName() === 'sqlite'
                                ? "INSTR('|' || watched.categories || '|', '|' || releases.categories_id || '|') > 0"
                                : "LOCATE(CONCAT('|', releases.categories_id, '|'), CONCAT('|', watched.categories, '|')) > 0";
                            $categories->whereNull('watched.categories')->orWhere('watched.categories', '')->orWhere('watched.categories', 'NULL')
                                ->orWhereRaw(str_replace('releases.categories_id', '?', $membership), [$root->categoryId()])->orWhereRaw($membership);
                        });
                    }
                    $this->whereRoot($titles, (int) $root->categoryId())->whereExists($subscription);
                });
            }
        });
    }
}
