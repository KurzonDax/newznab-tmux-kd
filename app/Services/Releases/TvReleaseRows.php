<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseRowData;
use App\Data\TvReleaseRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Loads a page of TV release ids into display rows: ReleaseRowFacts supplies the facts every
 * release list shows; this adds the show, the declared season and episode
 * (release_tv_episodes) and the episode title (tv_episodes by MIN(id)).
 */
final class TvReleaseRows
{
    public function __construct(private readonly ReleaseRowFacts $facts) {}

    /**
     * @param  list<int>  $ids  in display order
     * @return list<TvReleaseRow>
     */
    public function load(array $ids, bool $byAdded): array
    {
        $ordered = $this->facts->load($ids);
        if ($ordered === []) {
            return [];
        }
        $declared = $this->declarations(array_column($ordered, 'id'));
        $titles = $this->episodeTitles($ordered, $declared);
        $now = CarbonImmutable::now(config('app.timezone', 'UTC'));

        return array_map(function (object $release) use ($declared, $titles, $byAdded, $now): TvReleaseRow {
            /** @var ReleaseRowData $row */
            $row = $release->row_data;
            $show = $row->entity !== null && $row->entity->root === 'tv' ? $row->entity : null;
            $showId = $show === null ? null : (int) $show->id;
            $episode = $declared[(int) $release->id] ?? null;
            $episodeLabel = '';
            $showUrl = '';
            if ($showId !== null) {
                $showUrl = url('/tv/show/'.$showId);
                if ($episode !== null) {
                    $showUrl .= '/'.$episode['season'];
                    if ($episode['episode'] === null) {
                        $episodeLabel = 'Season '.$episode['season'].' pack';
                    } else {
                        $showUrl .= '?open='.$episode['episode'];
                        $title = $titles[$showId.'|'.$episode['season'].'|'.$episode['episode']] ?? '';
                        $episodeLabel = sprintf('S%02dE%02d', $episode['season'], $episode['episode']).($title === '' ? '' : ' · '.$title);
                    }
                }
            }

            return new TvReleaseRow(
                ...$this->facts->facts($release, $byAdded, $now),
                showId: $showId,
                showTitle: $show === null ? '' : $show->title,
                poster: $show?->artwork,
                episodeLabel: $episodeLabel,
                showUrl: $showUrl,
            );
        }, $ordered);
    }

    /**
     * The first season/episode each release declares (lowest season, then lowest episode, a
     * whole-season pack before an episode).
     *
     * @param  list<int>  $ids
     * @return array<int, array{season: int, episode: ?int}>
     */
    private function declarations(array $ids): array
    {
        $declared = [];
        foreach (DB::table('release_tv_episodes')->whereIn('releases_id', $ids)->orderBy('releases_id')->orderBy('season')->orderByRaw('episode IS NOT NULL')->orderBy('episode')->get(['releases_id', 'season', 'episode']) as $row) {
            $declared[(int) $row->releases_id] ??= ['season' => (int) $row->season, 'episode' => $row->episode === null ? null : (int) $row->episode];
        }

        return $declared;
    }

    /**
     * @param  list<object>  $releases
     * @param  array<int, array{season: int, episode: ?int}>  $declared
     * @return array<string, string> keyed "videos_id|season|episode"
     */
    private function episodeTitles(array $releases, array $declared): array
    {
        $wanted = [];
        foreach ($releases as $release) {
            $episode = $declared[(int) $release->id] ?? null;
            if ($episode !== null && $episode['episode'] !== null && (int) $release->videos_id > 0) {
                $wanted[(int) $release->videos_id.'|'.$episode['season'].'|'.$episode['episode']] = [(int) $release->videos_id, $episode['season'], $episode['episode']];
            }
        }
        if ($wanted === []) {
            return [];
        }
        $rows = DB::table('tv_episodes')->where(function (Builder $query) use ($wanted): void {
            foreach ($wanted as [$videosId, $season, $episode]) {
                $query->orWhere(static fn (Builder $one): Builder => $one->where('videos_id', $videosId)->where('series', $season)->where('episode', $episode));
            }
        })->orderBy('id')->get(['videos_id', 'series', 'episode', 'title']);
        $titles = [];
        foreach ($rows as $row) {
            $titles[$row->videos_id.'|'.$row->series.'|'.$row->episode] ??= (string) $row->title;
        }

        return $titles;
    }
}
