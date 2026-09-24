<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\ReleaseResolution;
use App\Enums\ReleaseSource;
use App\Models\Category;
use App\Services\MediaInfo\Enums\MediaInfoSourceCompleteness;
use App\Services\MediaInfo\MediaInfoSnapshotService;
use App\Support\ReleaseQuality;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the per-release facts derived from other release data (`resolution`, `source`,
 * the `release_tv_episodes` rows) in step. Called only from SearchService::updateRelease(),
 * which every release change already reaches.
 */
final class ReleaseDerivedFacts
{
    public function __construct(
        private readonly MediaInfoSnapshotService $snapshots,
        private readonly TvReleaseMembership $membership,
    ) {}

    public function refresh(int $releaseId): void
    {
        $release = DB::table('releases')->where('id', $releaseId)
            ->first(['searchname', 'categories_id', 'videos_id', 'tv_episodes_id', 'resolution', 'source']);
        if ($release === null) {
            return;
        }

        $this->refreshQuality($releaseId, $release);
        $this->refreshTvEpisodes($releaseId, $release);
    }

    private function refreshQuality(int $releaseId, object $release): void
    {
        $name = (string) $release->searchname;
        [$width, $height] = $this->measuredSize($releaseId);
        $facts = [
            'resolution' => ReleaseQuality::resolution($width, $height, $name)->value,
            'source' => ReleaseQuality::source($name)->value,
        ];
        if ((int) $release->resolution === $facts['resolution'] && (int) $release->source === $facts['source']) {
            return;
        }

        DB::table('releases')->where('id', $releaseId)->update($facts);
    }

    /**
     * Replaces the release's `release_tv_episodes` rows with what it declares, only when
     * they differ. A NULL `episode` is the whole season, never episode 0.
     */
    private function refreshTvEpisodes(int $releaseId, object $release): void
    {
        $declared = [];
        if ($this->isTvWithShow($release)) {
            $linked = (int) $release->tv_episodes_id;
            $declared = $this->declaredEpisodes($release, $linked > 0 ? $this->linkedEpisodes([$linked]) : []);
        }
        $stored = DB::table('release_tv_episodes')->where('releases_id', $releaseId)->orderBy('id')->get(['season', 'episode'])
            ->map(static fn (object $row): array => ['season' => (int) $row->season, 'episode' => $row->episode === null ? null : (int) $row->episode])
            ->all();
        if ($stored === $declared) {
            return;
        }

        DB::transaction(static function () use ($releaseId, $declared): void {
            DB::table('release_tv_episodes')->where('releases_id', $releaseId)->delete();
            DB::table('release_tv_episodes')->insert(array_map(
                static fn (array $row): array => ['releases_id' => $releaseId] + $row, $declared));
        });
    }

    /**
     * Writes `release_tv_episodes` for every TV release with a show, in primary-key chunks,
     * for the migration that adds the table. Each chunk replaces its releases' rows, so it
     * can be re-run.
     */
    public function fillTvEpisodes(): int
    {
        $written = 0;
        DB::table('releases')->whereBetween('categories_id', [Category::TV_ROOT, Category::TV_ROOT + 999])->where('videos_id', '>', 0)
            ->select(['id', 'searchname', 'categories_id', 'videos_id', 'tv_episodes_id'])
            ->chunkById(500, function (Collection $releases) use (&$written): void {
                $linked = $this->linkedEpisodes($releases->pluck('tv_episodes_id')->map(static fn (mixed $id): int => (int) $id)
                    ->filter(static fn (int $id): bool => $id > 0)->unique()->values()->all());
                $rows = [];
                foreach ($releases as $release) {
                    foreach ($this->declaredEpisodes($release, $linked) as $row) {
                        $rows[] = ['releases_id' => (int) $release->id] + $row;
                    }
                }
                DB::transaction(static function () use ($releases, $rows): void {
                    DB::table('release_tv_episodes')->whereIn('releases_id', $releases->pluck('id')->all())->delete();
                    DB::table('release_tv_episodes')->insert($rows);
                });
                $written += count($rows);
            });

        return $written;
    }

    private function isTvWithShow(object $release): bool
    {
        return intdiv((int) $release->categories_id, 1000) * 1000 === Category::TV_ROOT && (int) $release->videos_id > 0;
    }

    /**
     * The parser's declaration as rows; a name that declares nothing falls back to the
     * linked `tv_episodes` row.
     *
     * @param  array<int, array{season: int, episode: int}>  $linked  Linked episodes by `tv_episodes.id`.
     * @return list<array{season: int, episode: ?int}>
     */
    private function declaredEpisodes(object $release, array $linked): array
    {
        $declaration = $this->membership->describe($release);
        if ($declaration['fullSeason']) {
            return [['season' => (int) $declaration['season'], 'episode' => null]];
        }
        if ($declaration['season'] !== null) {
            return array_map(static fn (int $episode): array => ['season' => $declaration['season'], 'episode' => $episode],
                $declaration['numbers'] ?? []);
        }

        return isset($linked[$declaration['linked']]) ? [$linked[$declaration['linked']]] : [];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{season: int, episode: int}>
     */
    private function linkedEpisodes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('tv_episodes')->whereIn('id', $ids)->get(['id', 'series', 'episode'])
            ->mapWithKeys(static fn (object $row): array => [(int) $row->id => ['season' => (int) $row->series, 'episode' => (int) $row->episode]])
            ->all();
    }

    /**
     * Sets both columns for every release in one statement: the same rule as refresh(),
     * written in SQL for the migration that adds the columns. Needs REGEXP and
     * REGEXP_SUBSTR with PCRE semantics (MariaDB).
     */
    public function fillAll(): int
    {
        $bindings = [MediaInfoSourceCompleteness::Complete->value, ReleaseQuality::RESOLUTION_PATTERN];
        $sourceCases = '';
        foreach (ReleaseSource::precedence() as $source) {
            $sourceCases .= ' WHEN releases.searchname REGEXP ? THEN '.$source->value;
            $bindings[] = ReleaseQuality::sourcePattern($source);
        }
        $nameCases = '';
        foreach (ReleaseResolution::NAME_TOKEN_PREFIXES as $prefix => $resolution) {
            $nameCases .= " WHEN '{$prefix}' THEN ".$resolution->value;
        }

        $probe = <<<SQL
            SELECT {$this->measuredCase('t.width', 't.height')}
            FROM media_info_tracks t
            WHERE t.media_info_probe_id = (
                SELECT p.id FROM media_info_probes p
                WHERE p.releases_id = releases.id
                    AND (SELECT COUNT(*) FROM media_info_probes n
                        WHERE n.releases_id = p.releases_id
                            AND (n.captured_at > p.captured_at OR (n.captured_at = p.captured_at AND n.id > p.id))) < 2
                ORDER BY CASE WHEN p.source_completeness = ? THEN 0 ELSE 1 END, p.captured_at DESC, p.id DESC
                LIMIT 1)
                AND t.type = 'video' AND (COALESCE(t.width, 0) > 0 OR COALESCE(t.height, 0) > 0)
            ORDER BY t.id
            LIMIT 1
            SQL;
        $videoData = <<<SQL
            SELECT {$this->measuredCase('v.videowidth', 'v.videoheight')}
            FROM video_data v
            WHERE v.releases_id = releases.id AND (COALESCE(v.videowidth, 0) > 0 OR COALESCE(v.videoheight, 0) > 0)
            SQL;
        $unknownSource = ReleaseSource::Unknown->value;
        $unknownResolution = ReleaseResolution::Unknown->value;

        return DB::update(<<<SQL
            UPDATE releases SET
                resolution = COALESCE(({$probe}), ({$videoData}),
                    CASE SUBSTR(REGEXP_SUBSTR(releases.searchname, ?), 1, 3){$nameCases} ELSE {$unknownResolution} END),
                source = CASE{$sourceCases} ELSE {$unknownSource} END
            SQL, $bindings);
    }

    /** @return array{int, int} Zeros when nothing was measured. */
    private function measuredSize(int $releaseId): array
    {
        foreach ($this->snapshots->selectedForRelease($releaseId)->streams ?? [] as $stream) {
            $width = (int) ($stream['width'] ?? 0);
            $height = (int) ($stream['height'] ?? 0);
            if ($stream['type'] === 'video' && ($width > 0 || $height > 0)) {
                return [$width, $height];
            }
        }

        $video = DB::table('video_data')->where('releases_id', $releaseId)->first(['videowidth', 'videoheight']);

        return [(int) ($video->videowidth ?? 0), (int) ($video->videoheight ?? 0)];
    }

    private function measuredCase(string $width, string $height): string
    {
        return sprintf(
            'CASE WHEN %1$s >= %3$d OR %2$s >= %4$d THEN %5$d WHEN %1$s >= %6$d OR %2$s >= %7$d THEN %8$d'
            .' WHEN %1$s >= %9$d OR %2$s >= %10$d THEN %11$d ELSE %12$d END',
            $width, $height,
            ReleaseResolution::UHD_MIN_WIDTH, ReleaseResolution::UHD_MIN_HEIGHT, ReleaseResolution::Uhd->value,
            ReleaseResolution::FULL_HD_MIN_WIDTH, ReleaseResolution::FULL_HD_MIN_HEIGHT, ReleaseResolution::FullHd->value,
            ReleaseResolution::HD_MIN_WIDTH, ReleaseResolution::HD_MIN_HEIGHT, ReleaseResolution::Hd->value,
            ReleaseResolution::Sd->value,
        );
    }
}
