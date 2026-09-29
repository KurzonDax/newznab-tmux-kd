<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseListFilters;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A releases list's remembered dropdown filters (issue #881): the set on screen after the user's
 * last change, kept in the user's view preferences beside the list's sort, under the list's root:
 * view_prefs[root]['filters'] (the filters' URL query) and view_prefs[root]['filters_at'] (when
 * that set was chosen, in milliseconds on the server's clock).
 *
 * Only the list's dropdown filter keys count. A full-page open whose URL carries none of them
 * shows the remembered set by redirecting to the list URL that carries it (the page kept). A URL
 * that carries any of them, and every list refresh (?_fragment=list), becomes the remembered set
 * exactly, menus it leaves empty included. A refresh sends the time it was made (STAMP, from the
 * page's data-filters-clock), so a refresh the server finishes after a newer change never
 * replaces it. Clear all (?clear) forgets the set and opens the bare list.
 */
final readonly class RememberedListFilters
{
    /** The query key of Clear all's link. */
    public const CLEAR = 'clear';

    /** The query key of the time a list refresh was made, in milliseconds on the server's clock. */
    public const STAMP = '_filters_at';

    /**
     * @param  string  $root  the list's view_prefs root (tv, movies)
     * @param  string  $route  the list's route name
     * @param  list<string>  $keys  the list's dropdown filter URL keys
     */
    public function __construct(private string $root, private string $route, private array $keys) {}

    /**
     * The list's filters for this request, or the redirect that opens it: to the bare list after
     * Clear all, or to the URL that carries the remembered set on a bare open.
     *
     * @template T of ReleaseListFilters
     *
     * @param  Closure(Request): T  $read  reads the list's filters from a request
     * @return T|RedirectResponse
     */
    public function open(Request $request, User $user, Closure $read): ReleaseListFilters|RedirectResponse
    {
        $fragment = $request->query('_fragment') === 'list';
        if (! $fragment && $request->query->has(self::CLEAR)) {
            $this->store($user->id, [], null);

            return redirect()->route($this->route);
        }
        if (! $fragment && ! $this->carries($request)) {
            $filters = $read($this->recalled($request, $user));

            return $filters->any() ? redirect()->route($this->route, $filters->query()) : $filters;
        }
        $filters = $read($request);
        $this->store($user->id, array_intersect_key($filters->query(1), array_flip($this->keys)), $fragment ? $this->stamp($request) : null);

        return $filters;
    }

    /** The time to render as the page's data-filters-clock, which its refreshes count on from. */
    public static function clock(): int
    {
        return Carbon::now()->getTimestampMs();
    }

    private function carries(Request $request): bool
    {
        foreach ($this->keys as $key) {
            if ($request->query->has($key)) {
                return true;
            }
        }

        return false;
    }

    /** The request with the remembered set in place of its query, the page kept. */
    private function recalled(Request $request, User $user): Request
    {
        $stored = $user->releaseViewPreferences($this->root)['filters'] ?? [];
        $query = [];
        foreach (is_array($stored) ? array_intersect_key($stored, array_flip($this->keys)) : [] as $key => $value) {
            $query[$key] = is_array($value) ? array_map('strval', array_filter($value, 'is_scalar')) : (is_scalar($value) ? (string) $value : null);
        }
        if (is_string($request->query('page'))) {
            $query['page'] = $request->query('page');
        }

        return $request->duplicate(array_filter($query, static fn (mixed $value): bool => $value !== null));
    }

    /** A refresh's time: never ahead of the server's clock, so it cannot shut later changes out. */
    private function stamp(Request $request): int
    {
        $stamp = $request->query(self::STAMP);

        return is_string($stamp) && ctype_digit($stamp) ? min((int) $stamp, self::clock()) : self::clock();
    }

    /**
     * Remembers the set; a refresh ($stamp) only when it is not older than the remembered one, an
     * open always (it follows every refresh the page before it sent).
     *
     * @param  array<string, mixed>  $filters
     */
    private function store(int $userId, array $filters, ?int $stamp): void
    {
        DB::transaction(function () use ($userId, $filters, $stamp): void {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $stored = $user->view_prefs ?? [];
            $list = is_array($stored[$this->root] ?? null) ? $stored[$this->root] : [];
            $last = is_int($list['filters_at'] ?? null) ? $list['filters_at'] : 0;
            if ($stamp !== null && $stamp < $last) {
                return;
            }
            $list['filters'] = $filters;
            $list['filters_at'] = max($stamp ?? self::clock(), $last);
            $stored[$this->root] = $list;
            $user->view_prefs = $stored;
            $user->save();
        });
    }
}
