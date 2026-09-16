<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseBrowserState;
use App\Enums\BrowseRoot;
use App\Models\Category;
use App\Models\User;
use App\Services\EpisodeHydrationService;
use App\Support\ReleaseQuality;
use App\Support\YearRange;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class TitleReleaseBrowser
{
    public function __construct(
        private readonly TitleMetadataLoader $metadata,
        private readonly ReleaseBrowserQuery $browser,
        private readonly ReleaseBrowseService $rows,
        private readonly EpisodeHydrationService $episodes,
    ) {}

    /** @return array<string, mixed> */
    public function load(BrowseRoot $root, string $id, User $user, Request $request): array
    {
        $categoryId = null;
        $category = $request->input('t');
        if (is_scalar($category) && ctype_digit((string) $category) && (int) $category > 0 && (int) $category !== $root->categoryId()) {
            $categoryId = (int) $category;
            abort_unless(Category::query()->whereKey($categoryId)->where('root_categories_id', $root->categoryId())->exists(), 404);
            abort_if(in_array($categoryId, (array) $user->categoryexclusions), 403);
        }
        $state = new ReleaseBrowserState(root: $root, view: 'table', size: 's', per: 100, thumbs: false,
            page: max(1, (int) $request->integer('page', 1)), group: '', posterIdentity: '', categoryId: $categoryId,
            query: '', sort: 'newest', filters: [], watching: false, basketOnly: false, minCompletion: 0, tableOnly: true);
        $source = $this->metadata->source($root);
        $query = $this->browser->matchingQuery($state, $user)->where('r.'.$source['releaseKey'], $id);
        $year = null;
        $columns = ['r.id', 'r.searchname', 'r.display_name', 'r.adddate', 'r.tv_episodes_id'];
        if ($root === BrowseRoot::Tv) {
            $query->leftJoin('tv_episodes as title_episode', function (JoinClause $join): void {
                $join->on('title_episode.id', '=', 'r.tv_episodes_id')->on('title_episode.videos_id', '=', 'r.videos_id');
            });
            $columns = [...$columns, 'title_episode.series', 'title_episode.episode', 'title_episode.firstaired'];
            $year = YearRange::fromInput($this->scalar($request, 'year'), $this->scalar($request, 'year_from'), $this->scalar($request, 'year_to'));
            if (($start = $year?->startDate()) !== null) {
                $query->where('title_episode.firstaired', '>=', $start);
            }
            if (($end = $year?->endDate()) !== null) {
                $query->where('title_episode.firstaired', '<=', $end);
            }
        }
        $references = (clone $query)->get($columns);
        if ($root === BrowseRoot::Tv) {
            $this->hydrateSeasons($references);
        }
        foreach ($references as $reference) {
            $reference->quality = ReleaseQuality::fromName($root, release_display_name($reference));
        }
        $qualities = $references->pluck('quality')->filter()->unique()->sortBy(fn (string $quality): int => $this->qualityOrder($quality))->values()->all();
        $requestedQualities = $request->input('quality', []);
        $activeQualities = is_array($requestedQualities) ? array_values(array_intersect($qualities, array_filter($requestedQualities, 'is_string'))) : [];
        $seasonCounts = $root === BrowseRoot::Tv ? $references->countBy('series')->sortKeys()->all() : [];
        if (array_key_exists(0, $seasonCounts)) {
            $specials = $seasonCounts[0];
            unset($seasonCounts[0]);
            $seasonCounts[0] = $specials;
        }
        $requestedSeason = $this->scalar($request, 'season');
        $numberedSeasons = array_filter(array_keys($seasonCounts), static fn ($season): bool => (int) $season > 0);
        $selectedSeason = $root !== BrowseRoot::Tv ? null : ($requestedSeason !== '' && ctype_digit($requestedSeason)
            ? (int) $requestedSeason : ($numberedSeasons === [] ? 0 : max($numberedSeasons)));
        $selected = $references->filter(static fn (object $release): bool => ($selectedSeason === null || $release->series === $selectedSeason)
            && ($activeQualities === [] || in_array($release->quality, $activeQualities, true)));
        $selected = $selected->sort(function (object $left, object $right) use ($root): int {
            if ($root === BrowseRoot::Tv) {
                $episodeOrder = ($left->episode > 0 ? $left->episode : PHP_INT_MAX) <=> ($right->episode > 0 ? $right->episode : PHP_INT_MAX);
                if ($episodeOrder !== 0) {
                    return $episodeOrder;
                }
            }

            return strcmp((string) $right->adddate, (string) $left->adddate) ?: ($right->id <=> $left->id);
        })->values();
        $page = min($state->page, max(1, (int) ceil($selected->count() / 100)));
        $ids = $selected->slice(($page - 1) * 100, 100)->pluck('id');
        $releaseRows = $ids->isEmpty() ? collect() : (clone $query)->whereIn('r.id', $ids)->get(['r.*'])->keyBy('id');
        $releaseRows = $ids->map(static fn ($id): object => $releaseRows->get($id))->values();
        $this->rows->loadReleaseRows($releaseRows);
        $parameters = $request->except('_fragment');
        $results = new LengthAwarePaginator($releaseRows, $selected->count(), 100, $page, ['path' => $request->url(), 'query' => $parameters]);
        $seasons = [];
        foreach ($seasonCounts as $season => $count) {
            $seasons[] = ['number' => (int) $season, 'label' => $season === 0 ? 'Specials' : 'Season '.$season, 'count' => $count,
                'url' => $request->url().'?'.http_build_query([...$parameters, 'season' => $season, 'page' => 1])];
        }

        return [
            'results' => $results, 'browserState' => $state, 'seasons' => $seasons, 'selectedSeason' => $selectedSeason,
            'qualities' => $qualities, 'activeQualities' => $activeQualities,
            'releaseCount' => $references->count(), 'latestRelease' => $references->max('adddate'),
            'bestQuality' => $qualities[0] ?? null, 'seasonPackCount' => $root === BrowseRoot::Tv ? $references->where('series', '>', 0)->where('episode', 0)->count() : 0,
            'episodeCount' => $root === BrowseRoot::Tv ? $selected->where('episode', '>', 0)->pluck('episode')->unique()->count() : 0,
            'selectedPackCount' => $root === BrowseRoot::Tv ? $selected->where('series', '>', 0)->where('episode', 0)->count() : 0,
            'yearFilter' => $year === null ? null : ($year->from === $year->to ? (string) $year->from : ($year->from ?? '…').'–'.($year->to ?? '…')),
            'clearYearUrl' => $request->fullUrlWithoutQuery(['year', 'year_from', 'year_to', 'page', '_fragment']),
        ];
    }

    /** @param Collection<int, \stdClass> $references */
    private function hydrateSeasons(Collection $references): void
    {
        $fallback = $references->filter(static fn (object $release): bool => $release->series === null);
        foreach ($fallback as $release) {
            $release->tv_episodes_id = null;
        }
        $this->episodes->hydrateEpisodeMetadata($fallback);
        foreach ($references as $release) {
            if ($release->series === null && preg_match('/\b(?:S|Season[ ._-]*)(\d{1,4})\b/i', $release->searchname, $season)) {
                $release->series = (int) $season[1];
            }
            $release->series = (int) ($release->series ?? 0);
            $release->episode = (int) ($release->episode ?? 0);
        }
    }

    private function qualityOrder(string $quality): int
    {
        $order = ['2160p', '2160i', '1080p', '1080i', '720p', '720i', '576p', '576i', '480p', '480i',
            '24-bit FLAC', 'FLAC', 'ALAC', 'WAV', 'MP3', 'AAC', 'OPUS', 'OGG', 'EPUB', 'PDF', 'MOBI', 'AZW3', 'AZW'];

        return (int) array_search($quality, $order, true);
    }

    private function scalar(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_scalar($value) ? (string) $value : '';
    }
}
