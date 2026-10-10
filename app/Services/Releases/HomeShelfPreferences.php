<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\HomeShelf;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What the home page remembers per user (docs/proposals/home-redesign/SPEC.md 5, DATA-NOTES.md 4):
 * the key `home` of `users.view_prefs` holding `shelves` (the nine names in the user's order),
 * `ticked` (the ticked names), `seen_at` and `last_visit`. The default set and order apply while
 * the two lists are absent; all nine shelves always have a place, so a shelf whose section the
 * user may not view keeps its stored place and tick and is only left out when the page is drawn.
 */
final class HomeShelfPreferences
{
    public const string KEY = 'home';

    /** A full-page render this long after the last one makes that one the previous visit. */
    private const int VISIT_GAP_SECONDS = 1800;

    /**
     * @param  array<string, mixed>|null  $viewPrefs  the user's `view_prefs`
     * @return array{order: list<HomeShelf>, ticked: list<HomeShelf>, lastVisit: ?int}
     */
    public static function read(?array $viewPrefs): array
    {
        $home = is_array($viewPrefs[self::KEY] ?? null) ? $viewPrefs[self::KEY] : [];
        $order = self::shelves($home['shelves'] ?? null) ?? [];
        foreach (HomeShelf::cases() as $shelf) {
            if (! in_array($shelf, $order, true)) {
                $order[] = $shelf;
            }
        }

        return [
            'order' => $order,
            'ticked' => self::shelves($home['ticked'] ?? null) ?? HomeShelf::defaultTicked(),
            'lastVisit' => is_int($home['last_visit'] ?? null) ? $home['last_visit'] : null,
        ];
    }

    /**
     * The shelves the user may view: a section shelf needs its section's permission, Following
     * needs TV or Movies.
     *
     * @return list<HomeShelf>
     */
    public function viewable(User $user): array
    {
        $hidden = $user->hiddenRootCategoryIds();
        $sees = static fn (HomeShelf $shelf): bool => ! in_array($shelf->root()?->categoryId(), $hidden, true);

        return array_values(array_filter(HomeShelf::cases(), static fn (HomeShelf $shelf): bool => $shelf === HomeShelf::Following
            ? $sees(HomeShelf::Tv) || $sees(HomeShelf::Movies)
            : $sees($shelf)));
    }

    /**
     * Saves the dialog's change. `$shelves` is the order of the rows the dialog lists: they take,
     * in that order, the places they hold in the stored order, so a shelf the dialog does not list
     * keeps its place. `$ticked` are the ticked rows among them (the request sends the two
     * together): a shelf that is not listed keeps its stored tick.
     *
     * @param  list<string>|null  $shelves
     * @param  list<string>|null  $ticked
     * @return array{shelves: list<string>, ticked: list<string>}
     */
    public function save(int $userId, ?array $shelves, ?array $ticked): array
    {
        return DB::transaction(function () use ($userId, $shelves, $ticked): array {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $stored = self::stored($user);
            $current = self::read($stored);
            $listed = self::shelves($shelves);
            $order = $current['order'];
            if ($listed !== null) {
                $next = 0;
                foreach ($order as $place => $shelf) {
                    if (in_array($shelf, $listed, true)) {
                        $order[$place] = $listed[$next++];
                    }
                }
            }
            $on = $current['ticked'];
            if ($ticked !== null) {
                $kept = array_filter($on, static fn (HomeShelf $shelf): bool => ! in_array($shelf, $listed ?? [], true));
                $on = [...$kept, ...(self::shelves($ticked) ?? [])];
            }
            $home = is_array($stored[self::KEY] ?? null) ? $stored[self::KEY] : [];
            $names = static fn (array $list): array => array_values(array_map(static fn (HomeShelf $shelf): string => $shelf->value, $list));
            if ($listed !== null || array_key_exists('shelves', $home)) {
                $home['shelves'] = $names($order);
            }
            if ($ticked !== null) {
                // in the page's order, so the stored list reads as the page does
                $home['ticked'] = $names(array_filter($order, static fn (HomeShelf $shelf): bool => in_array($shelf, $on, true)));
            }
            $stored[self::KEY] = $home;
            $user->setAttribute('view_prefs', $stored);
            $user->save();

            return ['shelves' => $names($order), 'ticked' => $home['ticked'] ?? $names($current['ticked'])];
        });
    }

    /**
     * Records a full-page render of the home page (SPEC 3.4) and returns the previous visit the
     * "new" badges compare against: `seen_at` becomes `last_visit` when this render comes more
     * than 30 minutes after it, then `seen_at` is now. Null on a first visit. The lock and re-read
     * keep a tick saved at the same moment.
     */
    public function visit(int $userId): ?int
    {
        return DB::transaction(function () use ($userId): ?int {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $stored = self::stored($user);
            $home = is_array($stored[self::KEY] ?? null) ? $stored[self::KEY] : [];
            $now = now()->getTimestamp();
            $seen = is_int($home['seen_at'] ?? null) ? $home['seen_at'] : null;
            if ($seen !== null && $now - $seen > self::VISIT_GAP_SECONDS) {
                $home['last_visit'] = $seen;
            }
            $home['seen_at'] = $now;
            $stored[self::KEY] = $home;
            $user->setAttribute('view_prefs', $stored);
            $user->save();

            return is_int($home['last_visit'] ?? null) ? $home['last_visit'] : null;
        });
    }

    /**
     * The user's whole `view_prefs`: the list roots' preferences and, under `home`, this page's.
     *
     * @return array<string, mixed>
     */
    private static function stored(User $user): array
    {
        $stored = $user->getAttribute('view_prefs');

        return is_array($stored) ? $stored : [];
    }

    /**
     * A stored or posted list as distinct shelves; null when it is not a list.
     *
     * @return list<HomeShelf>|null
     */
    private static function shelves(mixed $names): ?array
    {
        if (! is_array($names)) {
            return null;
        }
        $shelves = [];
        foreach ($names as $name) {
            $shelf = is_string($name) ? HomeShelf::tryFrom($name) : null;
            if ($shelf !== null && ! in_array($shelf, $shelves, true)) {
                $shelves[] = $shelf;
            }
        }

        return $shelves;
    }
}
