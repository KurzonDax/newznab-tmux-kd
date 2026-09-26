<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseBrowserState;
use App\Data\WatchlistEntry;
use App\Enums\BrowseRoot;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WatchlistService
{
    public function __construct(private readonly TitleMetadataLoader $titles) {}

    public function subscriptions(BrowseRoot $root, User $user): Builder
    {
        return DB::table($root === BrowseRoot::Movies ? 'user_movies' : 'user_series')->where('users_id', $user->id);
    }

    /** @return array<int, string> */
    public function categories(BrowseRoot $root, User $user): array
    {
        return DB::table('categories')->where('root_categories_id', $root->categoryId())
            ->whereNotIn('id', (array) $user->categoryexclusions)->orderBy('id')->pluck('title', 'id')->all();
    }

    /**
     * @param  list<int>  $available
     * @return list<int>
     */
    public function selected(?string $stored, BrowseRoot $root, array $available): array
    {
        $ids = array_map('intval', explode('|', $stored ?? ''));

        return in_array($stored, [null, '', 'NULL'], true) || in_array($root->categoryId(), $ids, true)
            ? $available : array_values(array_intersect($available, $ids));
    }

    /**
     * @param  list<string>|null  $lastChoice
     * @return array<string, mixed>
     */
    public function picker(BrowseRoot $root, string $id, User $user, ?array $lastChoice = null): array
    {
        $source = $this->titles->source($root);
        $title = DB::table($source['table'])->where($source['key'], $id)->value('title');
        abort_if($title === null, 404);
        $record = $this->subscriptions($root, $user)->where($source['releaseKey'], $id)->first();
        $categories = $this->categories($root, $user);
        $lastChoice ??= $this->lastChoice($user);
        $selected = $record !== null ? $this->selected($record->categories, $root, array_keys($categories))
            : array_keys(array_filter($categories, static fn (string $label): bool => in_array(mb_strtoupper($label), $lastChoice ?? ['UHD', 'HD'], true)));

        return [
            'root' => $root->value, 'id' => $id, 'title' => $title, 'watched' => $record !== null,
            'listName' => $root === BrowseRoot::Movies ? 'My Movies' : 'My Shows',
            'categories' => collect($categories)->map(static fn (string $label, int $id): array => compact('id', 'label'))->values()->all(),
            'selected' => $selected, 'url' => route('watchlist.picker', ['root' => $root->value, 'id' => $id]),
            'watchlistUrl' => route('watchlist', ['tab' => $root->value]), 'counts' => $this->counts($user),
        ];
    }

    /** @return list<string>|null */
    private function lastChoice(User $user): ?array
    {
        $movie = $this->subscriptions(BrowseRoot::Movies, $user)->select(['categories', 'updated_at'])->selectRaw("'movies' AS root");
        $tv = $this->subscriptions(BrowseRoot::Tv, $user)->select(['categories', 'updated_at'])->selectRaw("'tv' AS root");
        $last = DB::query()->fromSub($movie->unionAll($tv), 'choices')->whereNotNull('categories')->whereNotIn('categories', ['', 'NULL'])->orderByDesc('updated_at')->first();
        if ($last === null) {
            return null;
        }
        $root = BrowseRoot::from($last->root);
        $categories = $this->categories($root, $user);
        $ids = $this->selected($last->categories, $root, array_keys($categories));

        return array_map(mb_strtoupper(...), array_values(array_intersect_key($categories, array_flip($ids))));
    }

    /** @param list<int> $categories */
    public function save(BrowseRoot $root, string $id, User $user, array $categories): void
    {
        $this->write($root, $id, $user, implode('|', $categories));
    }

    private function write(BrowseRoot $root, string $id, User $user, ?string $stored): void
    {
        DB::transaction(function () use ($root, $id, $user, $stored): void {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $key = $root === BrowseRoot::Movies ? 'imdbid' : 'videos_id';
            $query = $this->subscriptions($root, $user)->where($key, $id);
            $values = ['categories' => $stored, 'updated_at' => now()];
            if ($query->exists()) {
                $query->update($values);
            } else {
                $query->insert([...$values, 'users_id' => $user->id, $key => $id, 'created_at' => now()]);
            }
        });
        Cache::forget('composer_user_'.$user->id);
    }

    /** @return array{categories:list<int>, undoToken:?string} */
    public function remove(BrowseRoot $root, string $id, User $user): array
    {
        $removed = DB::transaction(function () use ($root, $id, $user): array {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $query = $this->subscriptions($root, $user)->where($root === BrowseRoot::Movies ? 'imdbid' : 'videos_id', $id);
            $record = $query->first();
            $categories = $record === null ? [] : $this->selected($record->categories, $root, array_keys($this->categories($root, $user)));
            $undoToken = $record === null ? null : Crypt::encrypt([
                'user' => $user->id, 'root' => $root->value, 'id' => $id, 'categories' => $record->categories, 'expires' => now()->addMinutes(5)->timestamp,
            ]);
            $query->delete();

            return compact('categories', 'undoToken');
        });
        Cache::forget('composer_user_'.$user->id);

        return $removed;
    }

    public function restore(BrowseRoot $root, string $id, User $user, string $token): void
    {
        try {
            $saved = Crypt::decrypt($token);
        } catch (DecryptException) {
            $saved = null;
        }
        if (! is_array($saved) || ($saved['user'] ?? null) !== $user->id || ($saved['root'] ?? null) !== $root->value
            || ($saved['id'] ?? null) !== $id || ($saved['expires'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['undo_token' => 'This Undo has expired. Add the title again to follow it.']);
        }
        $this->write($root, $id, $user, $saved['categories']);
    }

    public function titles(BrowseRoot $root, User $user, ?string $find = null): Builder
    {
        $source = $this->titles->source($root);
        $query = DB::table($source['table'].' as title')->select('title.*');
        if ($root === BrowseRoot::Tv) {
            $query->leftJoin('tv_info as info', 'info.videos_id', '=', 'title.id')->addSelect('info.publisher as network');
        }
        $subscriptions = $this->subscriptions($root, $user)->select($source['releaseKey']);
        $query->whereIn('title.'.$source['key'], $subscriptions, not: $find !== null);
        if ($find !== null) {
            $query->whereRaw("title.title LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $find).'%']);
        }

        return $query->orderBy('title.title')->orderBy('title.'.$source['key']);
    }

    /**
     * @param  Collection<int, \stdClass>  $titles
     * @return Collection<int, WatchlistEntry>
     */
    public function present(Collection $titles, BrowseRoot $root, User $user, bool $followed): Collection
    {
        $source = $this->titles->source($root);
        $ids = $titles->pluck($source['key'])->map(static fn ($id): string => (string) $id)->all();
        $subscriptions = $followed ? $this->subscriptions($root, $user)->whereIn($source['releaseKey'], $ids)->pluck('categories', $source['releaseKey']) : collect();
        $latest = collect();
        if ($followed && $ids !== []) {
            $state = ReleaseBrowserState::fromRequest(new Request(['watching' => '1']), $root, $user, tableOnly: true);
            $releases = app(ReleaseBrowserQuery::class)->matchingQuery($state, $user)->whereIn('r.'.$source['releaseKey'], $ids)
                ->select(['r.guid', 'r.searchname', 'r.display_name', 'r.adddate', 'r.'.$source['releaseKey']])
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY r.'.$source['releaseKey'].' ORDER BY r.adddate DESC, r.id DESC) AS latest_rank');
            $latest = DB::query()->fromSub($releases, 'latest')->where('latest_rank', 1)->get()->keyBy($source['releaseKey']);
        }
        $categories = $this->categories($root, $user);

        return $titles->map(function (object $title) use ($source, $root, $followed, $subscriptions, $latest, $categories): WatchlistEntry {
            $id = (string) $title->{$source['key']};
            $selected = $followed ? $this->selected($subscriptions->get($id), $root, array_keys($categories)) : [];

            return new WatchlistEntry(
                id: $id, root: $root->value, title: (string) $title->title, watched: $followed,
                year: substr((string) ($root === BrowseRoot::Movies ? ($title->year ?? '') : ($title->started ?? '')), 0, 4),
                network: (string) ($title->network ?? ''), latest: $latest->get($id),
                artwork: getImageAssetUrl($source['art'], $root === BrowseRoot::Movies ? $id.'-cover' : $id),
                url: $root === BrowseRoot::Tv ? route('tv.show', ['videosId' => $id]) : route('title', ['root' => $root->value, 'id' => $id]),
                categories: array_values(array_intersect_key($categories, array_flip($selected))),
            );
        });
    }

    /** @return array{movies:int, tv:int} */
    public function counts(User $user): array
    {
        return [
            'movies' => $user->getDirectPermissions()->contains('name', 'view movies') ? $this->subscriptions(BrowseRoot::Movies, $user)->distinct()->count('imdbid') : 0,
            'tv' => $user->getDirectPermissions()->contains('name', 'view tv') ? $this->subscriptions(BrowseRoot::Tv, $user)->distinct()->count('videos_id') : 0,
        ];
    }
}
