<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseBrowserState;
use App\Data\ReleaseCoverItem;
use App\Enums\BrowseRoot;
use App\Models\User;
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
            BrowseRoot::Audio => 'musicinfo_id',
            default => null,
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
            BrowseRoot::Audio => app(MusicService::class)->getMusicRange($state->page, $categories, $offset, $state->per, $order, $excluded, scope: $scope),
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
        $entity = collect($releases)->first()?->row_data?->entity;
        $line = match ($root) {
            BrowseRoot::Audio => [$cover->artist ?? '', $cover->year ?? ''],
            default => [],
        };
        $badge = (string) ($cover->genre ?? '');
        $metadata = match ($root) {
            BrowseRoot::Audio => [$cover->artist ?? '', $badge, $cover->publisher ?? ''],
            default => [],
        };
        $id = (string) $cover->id;

        return new ReleaseCoverItem(
            id: $id,
            title: (string) $cover->title, artwork: $entity?->artwork,
            identifyingLine: implode(' · ', array_filter($line)),
            releaseCount: (int) $cover->total_releases,
            footerBadge: $badge, footerValue: $cover->total_releases.' releases',
            year: $entity?->year, metadata: array_values(array_filter($metadata)),
            releases: array_values($releases),
            titleUrl: route('title', ['root' => $root->value, 'id' => $id]),
        );
    }
}
