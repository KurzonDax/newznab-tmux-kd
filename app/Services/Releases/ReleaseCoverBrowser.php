<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseBrowserState;
use App\Data\ReleaseCoverItem;
use App\Enums\BrowseRoot;
use App\Models\User;
use App\Services\BookService;
use App\Services\ConsoleService;
use App\Services\GamesService;
use App\Services\MovieBrowseService;
use App\Services\MusicService;
use App\Support\CoverBrowseResults;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ReleaseCoverBrowser
{
    public function letterPage(ReleaseBrowserState $state, User $user): int
    {
        $titles = app(ReleaseBrowserQuery::class)->matchingQuery($state, $user)
            ->whereNotNull('m.id')->where('m.title', '!=', '')
            ->select(['m.id', 'm.title'])->groupBy('m.id', 'm.title')
            ->selectRaw('ROW_NUMBER() OVER (ORDER BY m.title ASC, m.id ASC) AS title_position');
        $initial = 'UPPER(SUBSTR(title, 1, 1))';
        $ranked = DB::query()->fromSub($titles, 'titles');
        if ($state->letter === '#') {
            $ranked->whereRaw($initial.' NOT BETWEEN ? AND ?', ['A', 'Z']);
        } else {
            $ranked->whereRaw($initial.' = ?', [$state->letter]);
        }
        $position = $ranked->min('title_position') ?? 1;

        return (int) ceil($position / $state->per);
    }

    /** @return LengthAwarePaginator<int, \stdClass> */
    public function expanded(ReleaseBrowserState $state, User $user, string $id, int $page = 1, int $per = 24): LengthAwarePaginator
    {
        $column = match ($state->root) {
            BrowseRoot::Movies => 'imdbid',
            BrowseRoot::Audio => 'musicinfo_id', BrowseRoot::Console => 'consoleinfo_id',
            BrowseRoot::Games => 'gamesinfo_id', BrowseRoot::Books => 'bookinfo_id',
            BrowseRoot::Adult => 'guid', default => null,
        };
        abort_if($state->view !== 'covers' || $column === null || $id === '', 404);
        $per = in_array($per, [24, 48, 100], true) ? $per : 24;
        $query = app(ReleaseBrowserQuery::class)->matchingQuery($state, $user)->where('r.'.$column, $id);
        $total = $query->count();
        abort_if($total === 0, 404);
        $page = min(max(1, $page), (int) ceil($total / $per));
        $rows = $query->orderByDesc('r.adddate')->orderByDesc('r.id')->forPage($page, $per)->get(['r.*']);
        abort_if($rows->isEmpty(), 404);
        app(ReleaseBrowseService::class)->loadReleaseRows($rows);

        return new LengthAwarePaginator($rows, $total, $per, $page, ['pageName' => 'release_page']);
    }

    /** @return LengthAwarePaginator<int, ReleaseCoverItem> */
    public function paginate(ReleaseBrowserState $state, User $user): LengthAwarePaginator
    {
        $categories = [$state->categoryId ?? $state->root->categoryId()];
        $offset = ($state->page - 1) * $state->per;
        $order = $state->sort === 'title' ? 'title_asc' : '';
        $excluded = (array) $user->categoryexclusions;
        $scope = new CoverBrowseScope(app(ReleaseBrowserQuery::class)->matchingQuery($state, $user), $state);
        $covers = match ($state->root) {
            BrowseRoot::Movies => app(MovieBrowseService::class)->getMovieRange($state->page, $categories, $offset, $state->per, $order, excludedCats: $excluded, scope: $scope),
            BrowseRoot::Audio => app(MusicService::class)->getMusicRange($state->page, $categories, $offset, $state->per, $order, $excluded, scope: $scope),
            BrowseRoot::Console => app(ConsoleService::class)->getConsoleRange($state->page, $categories, $offset, $state->per, $order, $excluded, scope: $scope),
            BrowseRoot::Games => app(GamesService::class)->getGamesRange($state->page, $categories, $offset, $state->per, $order, excludedCats: $excluded, scope: $scope),
            BrowseRoot::Books => app(BookService::class)->getBookRange($state->page, $categories, $offset, $state->per, $order, $excluded, scope: $scope),
            BrowseRoot::Tv => app(TvEpisodeBrowser::class)->paginate($state, $user),
            BrowseRoot::Adult => $this->adult($state, $user),
            default => collect(),
        };
        $items = $covers->map(fn (object $cover): ReleaseCoverItem => $cover instanceof ReleaseCoverItem ? $cover : $this->item($cover, $state->root));

        $total = $covers instanceof CoverBrowseResults ? $covers->total : (int) ($covers->first()->_totalcount ?? 0);

        return new LengthAwarePaginator($items, $total, $state->per, $state->page, [
            'path' => request()->url(), 'query' => $state->queryParameters(request()),
        ]);
    }

    private function item(object $cover, BrowseRoot $root): ReleaseCoverItem
    {
        /** @var array<int, object> $releases */
        $releases = $cover->releases;
        $firstRow = collect($releases)->first()?->row_data;
        $entity = $firstRow?->entity;
        $line = match ($root) {
            BrowseRoot::Movies => [$cover->year ?? '', empty($cover->rating) ? '' : '★ '.$cover->rating],
            BrowseRoot::Tv => [$cover->publisher ?? ''],
            BrowseRoot::Audio => [$cover->artist ?? '', $cover->year ?? ''],
            BrowseRoot::Console => [$cover->platform ?? '', substr((string) ($cover->releasedate ?? ''), 0, 4)],
            BrowseRoot::Games => ['PC', substr((string) ($cover->releasedate ?? ''), 0, 4)],
            BrowseRoot::Books => [$cover->author ?? '', substr((string) ($cover->publishdate ?? ''), 0, 4)],
            default => [],
        };
        $badge = (string) match ($root) {
            BrowseRoot::Console => $cover->esrb ?? '',
            BrowseRoot::Tv => '',
            default => $cover->genre ?? '',
        };
        $metadata = match ($root) {
            BrowseRoot::Movies => [empty($cover->rating) ? '' : '★ '.$cover->rating, $badge],
            BrowseRoot::Tv => [$cover->publisher ?? ''],
            BrowseRoot::Audio => [$cover->artist ?? '', $badge, $cover->publisher ?? ''],
            BrowseRoot::Console => [$cover->platform ?? '', $cover->publisher ?? '', $badge],
            BrowseRoot::Games => ['PC', $cover->publisher ?? '', $badge],
            BrowseRoot::Books => [$cover->author ?? '', $cover->publisher ?? ''],
            default => [],
        };
        $id = (string) ($root === BrowseRoot::Movies ? $cover->imdbid : $cover->id);
        $watched = $firstRow->watched ?? false;

        return new ReleaseCoverItem(
            id: $id,
            title: (string) $cover->title, artwork: $entity?->artwork,
            identifyingLine: implode(' · ', array_filter($line)),
            releaseCount: (int) $cover->total_releases,
            footerBadge: $badge, footerValue: $cover->total_releases.' releases',
            year: $entity?->year, metadata: array_values(array_filter($metadata)),
            releases: array_values($releases),
            titleUrl: route('title', ['root' => $root->value, 'id' => $id]),
            watchUrl: match ($root) {
                BrowseRoot::Movies, BrowseRoot::Tv => route('watchlist.picker', ['root' => $root->value, 'id' => $id]),
                default => null,
            },
            watched: $watched,
        );
    }

    private function adult(ReleaseBrowserState $state, User $user): CoverBrowseResults
    {
        $page = app(ReleaseBrowserQuery::class)->paginate($state, $user);
        $items = $page->getCollection()->map(function (object $release): ReleaseCoverItem {
            $row = $release->row_data;
            $preview = $release->haspreview == 1 ? getImageAssetUrl('preview', $row->guid.'_thumb') : null;
            $sample = $release->jpgstatus == 1 ? getImageAssetUrl('sample', $row->guid.'_thumb') : null;

            return new ReleaseCoverItem(
                id: $row->guid, title: $row->name, artwork: $preview ?? $sample,
                identifyingLine: $row->size.' · '.$row->added, releaseCount: 1,
                artworkTag: $preview ? 'PREVIEW' : ($sample ? 'SAMPLE' : ''),
                footerBadge: $row->category, footerValue: $row->completion.'%',
            );
        });

        return new CoverBrowseResults($items, $page->total());
    }
}
