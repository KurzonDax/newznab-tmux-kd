<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\TvReleaseFilters;
use App\Data\TvShowEpisode;
use App\Data\TvShowFilters;
use App\Data\TvShowHeader;
use App\Enums\ReleaseResolution;
use App\Models\Category;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The show page's reads (docs/proposals/tv-redesign/DATA-CONTRACT.md section 4): every one
 * starts from the show's releases (`videos_id = ?`, band 5000, the user's visibility) and joins
 * what they declare in `release_tv_episodes`. Titles and air dates come from `tv_episodes` by
 * MIN(id) per (videos_id, series, episode); an episode with no such row is still listed.
 */
final class TvShowPage
{
    /** The Starring line names at most this many people, in TMDB's order. */
    public const STARRING_LIMIT = 8;

    public function __construct(private readonly ReleaseBrowseService $releases) {}

    /**
     * The header, or null when the show does not exist or the user may see none of its releases.
     *
     * @param  list<int>  $exclusions
     */
    public function header(int $videosId, array $exclusions): ?TvShowHeader
    {
        $show = DB::table('videos as v')->leftJoin('tv_info as t', 't.videos_id', '=', 'v.id')->leftJoin('networks as n', 'n.id', '=', 't.networks_id')
            ->where('v.id', $videosId)->where('v.type', 0)
            ->first(['v.title', 'v.started', 't.summary', 't.publisher', 't.original_language', 't.status', 't.content_rating_us', 't.premiered', 'n.name as network']);
        if ($show === null) {
            return null;
        }
        $releases = $this->visible($videosId, $exclusions)->count();
        if ($releases === 0) {
            return null;
        }
        $rating = (string) $show->content_rating_us;
        $status = array_search((int) $show->status, TvShowFilters::STATUSES, true);

        return new TvShowHeader(
            id: $videosId,
            title: (string) $show->title,
            poster: getImageAssetUrl('tvshows', (string) $videosId),
            network: trim((string) ($show->network ?? $show->publisher ?? '')),
            year: TvShowWall::year($show->premiered, $show->started),
            releases: $releases,
            summary: trim((string) $show->summary),
            genres: DB::table('video_genres as vg')->join('genres as g', 'g.id', '=', 'vg.genres_id')->where('g.type', Category::TV_ROOT)
                ->where('vg.videos_id', $videosId)->orderBy('g.title')->pluck('g.title', 'g.id')->map(static fn (mixed $title): string => (string) $title)->all(),
            tags: array_values(array_filter([
                TvShowWall::languageName((string) $show->original_language),
                $rating === 'NR' ? '' : $rating,
                $status === false ? '' : TvShowFilters::STATUS_LABELS[$status],
            ], static fn (string $tag): bool => $tag !== '')),
            starring: DB::table('video_people as vp')->join('people as p', 'p.id', '=', 'vp.people_id')->where('vp.videos_id', $videosId)
                ->orderBy('vp.position')->limit(self::STARRING_LIMIT)->pluck('p.name', 'p.id')->map(static fn (mixed $name): string => (string) $name)->all(),
        );
    }

    /**
     * The seasons the show's visible releases declare, Specials (0) first; whole-season packs count.
     *
     * @param  list<int>  $exclusions
     * @return list<int>
     */
    public function seasons(int $videosId, array $exclusions): array
    {
        return $this->declared($this->visible($videosId, $exclusions))->distinct()->orderBy('e.season')->pluck('e.season')
            ->map(static fn (mixed $season): int => (int) $season)->all();
    }

    /**
     * The season of the show's newest release by postdate that declares one.
     *
     * @param  list<int>  $exclusions
     */
    public function newestSeason(int $videosId, array $exclusions): ?int
    {
        $season = $this->declared($this->visible($videosId, $exclusions))
            ->orderByDesc('r.postdate')->orderByDesc('r.id')->orderBy('e.season')->value('e.season');

        return $season === null ? null : (int) $season;
    }

    /**
     * One row per declared episode of the season that has a release matching the filters, newest
     * episode first: its count, the known resolutions present (no chip for Unknown) and the size range.
     *
     * @param  list<int>  $exclusions
     * @return list<TvShowEpisode>
     */
    public function episodes(int $videosId, int $season, TvReleaseFilters $filters, array $exclusions): array
    {
        $groups = $this->declared($this->filtered($videosId, $filters, $exclusions))->where('e.season', $season)->whereNotNull('e.episode')
            ->groupBy('e.episode', 'r.resolution')
            ->selectRaw('e.episode, r.resolution, COUNT(DISTINCT r.id) AS releases, MIN(r.size) AS smallest, MAX(r.size) AS largest')
            ->get();
        $found = [];
        foreach ($groups as $group) {
            $episode = (int) $group->episode;
            $found[$episode] ??= ['releases' => 0, 'resolutions' => [], 'smallest' => PHP_FLOAT_MAX, 'largest' => 0.0];
            $found[$episode]['releases'] += (int) $group->releases;
            $resolution = ReleaseResolution::tryFrom((int) $group->resolution) ?? ReleaseResolution::Unknown;
            if ($resolution !== ReleaseResolution::Unknown) {
                $found[$episode]['resolutions'][] = $resolution;
            }
            $found[$episode]['smallest'] = min($found[$episode]['smallest'], (float) $group->smallest);
            $found[$episode]['largest'] = max($found[$episode]['largest'], (float) $group->largest);
        }
        krsort($found);
        $known = [];
        foreach (DB::table('tv_episodes')->where('videos_id', $videosId)->where('series', $season)->whereIn('episode', array_keys($found))
            ->orderBy('id')->get(['episode', 'title', 'firstaired']) as $row) {
            $known[(int) $row->episode] ??= $row;
        }
        $order = array_flip(array_values(array_map(static fn (ReleaseResolution $resolution): int => $resolution->value, TvReleaseFilters::RESOLUTIONS)));
        $episodes = [];
        foreach ($found as $number => $episode) {
            $resolutions = array_unique($episode['resolutions'], SORT_REGULAR);
            usort($resolutions, static fn (ReleaseResolution $a, ReleaseResolution $b): int => $order[$a->value] <=> $order[$b->value]);
            $title = trim((string) ($known[$number]->title ?? ''));
            $aired = (string) ($known[$number]->firstaired ?? '');
            $episodes[] = new TvShowEpisode(
                number: $number,
                title: $title === '' ? 'Episode '.$number : $title,
                aired: $aired === '' || str_starts_with($aired, '0000') ? '' : substr($aired, 0, 10),
                releases: $episode['releases'],
                resolutions: $resolutions,
                smallest: TvReleaseRows::size($episode['smallest']),
                largest: TvReleaseRows::size($episode['largest']),
            );
        }

        return $episodes;
    }

    /**
     * The ids of the releases that declare this episode, or with a null episode the whole-season packs.
     *
     * @param  list<int>  $exclusions
     * @return list<int>
     */
    public function episodeReleaseIds(int $videosId, int $season, ?int $episode, TvReleaseFilters $filters, array $exclusions): array
    {
        $query = $this->declared($this->filtered($videosId, $filters, $exclusions))->where('e.season', $season);
        $episode === null ? $query->whereNull('e.episode') : $query->where('e.episode', $episode);

        return $this->ids($query);
    }

    /**
     * "Other releases": the show's releases that declare no season or episode.
     *
     * @param  list<int>  $exclusions
     * @return list<int>
     */
    public function otherReleaseIds(int $videosId, TvReleaseFilters $filters, array $exclusions): array
    {
        return $this->ids($this->filtered($videosId, $filters, $exclusions)
            ->whereNotExists(static fn (Builder $declared) => $declared->selectRaw('1')->from('release_tv_episodes as e')->whereColumn('e.releases_id', 'r.id')));
    }

    /**
     * Largest first: the release table's default order (sorting again happens in the browser).
     *
     * @return list<int>
     */
    private function ids(Builder $query): array
    {
        return $query->distinct()->select(['r.id', 'r.size'])->orderByDesc('r.size')->orderByDesc('r.id')->get()
            ->map(static fn (object $release): int => (int) $release->id)->all();
    }

    private function declared(Builder $releases): Builder
    {
        return $releases->join('release_tv_episodes as e', 'e.releases_id', '=', 'r.id');
    }

    /** @param list<int> $exclusions */
    private function filtered(int $videosId, TvReleaseFilters $filters, array $exclusions): Builder
    {
        $query = $this->visible($videosId, $exclusions);
        if ($filters->resolutions !== []) {
            $query->whereIn('r.resolution', $filters->resolutionValues());
        }
        if ($filters->sources !== []) {
            $query->whereIn('r.source', $filters->sourceValues());
        }

        return $query;
    }

    /** @param list<int> $exclusions */
    private function visible(int $videosId, array $exclusions): Builder
    {
        $query = DB::table('releases as r')->where('r.videos_id', $videosId)->where('r.category_band', Category::TV_ROOT)
            ->whereRaw('r.passwordstatus '.$this->releases->showPasswords());
        if ($exclusions !== []) {
            $query->whereNotIn('r.categories_id', $exclusions);
        }

        return $query;
    }
}
