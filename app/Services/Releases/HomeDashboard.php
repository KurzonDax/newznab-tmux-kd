<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseBrowserState;
use App\Enums\BrowseRoot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class HomeDashboard
{
    public function __construct(private readonly ReleaseBrowserQuery $query, private readonly ReleaseBrowseService $releases, private readonly ReleaseCoverBrowser $covers) {}

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $latestState = $this->state(BrowseRoot::All, 'cards', 8);
        $latest = $this->query->paginate($latestState, $user);
        $watchedQuery = $this->query->matchingQuery($this->state(BrowseRoot::All, 'table', 5, watching: true), $user)
            ->select('r.*')->selectRaw("ROW_NUMBER() OVER (PARTITION BY CASE WHEN r.categories_id BETWEEN 2000 AND 2999 THEN r.imdbid ELSE CAST(r.videos_id AS CHAR) END, CASE WHEN r.categories_id BETWEEN 2000 AND 2999 THEN 'movie' ELSE 'tv' END ORDER BY r.adddate DESC, r.id DESC) AS title_rank");
        $watched = DB::query()->fromSub($watchedQuery, 'watched')->where('title_rank', 1)->orderByDesc('adddate')->orderByDesc('id')->limit(5)->get();
        $this->releases->loadReleaseRows($watched);
        $trendingRoot = $user->hasDirectPermission('view movies') ? BrowseRoot::Movies : ($user->hasDirectPermission('view tv') ? BrowseRoot::Tv : null);
        $trendingState = $trendingRoot ? $this->state($trendingRoot, 'covers', 6, sort: 'grabs', trending: true) : null;

        return [
            'latestState' => $latestState, 'latest' => $latest, 'homeWatched' => $watched,
            'trendingState' => $trendingState,
            'homeTrending' => $trendingState ? $this->covers->paginate($trendingState, $user) : null,
        ];
    }

    private function state(BrowseRoot $root, string $view, int $per, bool $watching = false, string $sort = 'newest', bool $trending = false): ReleaseBrowserState
    {
        return new ReleaseBrowserState(
            root: $root, view: $view, size: 's', per: $per, thumbs: true, page: 1,
            group: '', posterIdentity: '', categoryId: null, query: '', sort: $sort,
            filters: [], watching: $watching, basketOnly: false, minCompletion: 0, trending: $trending,
        );
    }
}
