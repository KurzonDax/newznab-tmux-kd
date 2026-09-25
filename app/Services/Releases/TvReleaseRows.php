<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseRowData;
use App\Data\TvReleaseRow;
use App\Enums\ReleaseResolution;
use App\Enums\ReleaseSource;
use App\Support\ReleaseCompletion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Loads a page of TV release ids into display rows: the shared row loader supplies the
 * facts every release list shows; this adds the show, the declared season and episode
 * (release_tv_episodes), the episode title (tv_episodes by MIN(id)) and the TV screen's
 * formats.
 */
final class TvReleaseRows
{
    private const CODECS = [
        'V_MPEG4/ISO/AVC' => 'H.264', 'AVC' => 'H.264', 'X264' => 'H.264', 'H264' => 'H.264',
        'V_MPEGH/ISO/HEVC' => 'H.265', 'HEVC' => 'H.265', 'X265' => 'H.265', 'H265' => 'H.265',
        'V_AV1' => 'AV1', 'AV1' => 'AV1', 'V_MPEG2' => 'MPEG-2', 'MPEG VIDEO' => 'MPEG-2',
    ];

    private const CHANNELS = ['1' => '1.0', '2' => '2.0', '6' => '5.1', '8' => '7.1'];

    public function __construct(private readonly ReleaseBrowseService $releases) {}

    /**
     * @param  list<int>  $ids  in display order
     * @return list<TvReleaseRow>
     */
    public function load(array $ids, bool $byAdded): array
    {
        if ($ids === []) {
            return [];
        }
        $releases = DB::table('releases')->whereIn('id', $ids)
            ->get([...ReleaseRowDataLoader::REQUIRED_COLUMNS, 'resolution', 'source'])->keyBy('id');
        $ordered = [];
        foreach ($ids as $id) {
            if ($releases->has($id)) {
                $ordered[] = $releases->get($id);
            }
        }
        $ordered = $this->releases->loadReleaseRows($ordered);
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
            $sortDate = $byAdded ? $release->adddate : $release->postdate;
            $completion = null;
            if (ReleaseCompletion::isIncomplete($release->completion)) {
                $percent = ReleaseCompletion::percent($release->completion);
                $completion = [
                    'percent' => $percent,
                    'band' => $percent >= 95 ? 'ok' : ($percent >= 75 ? 'mid' : 'low'),
                    'repairing' => ! ReleaseCompletion::repairAttemptsExhausted($release->repair_outcome, $release->rescan_outcome),
                ];
            }
            $source = ReleaseSource::tryFrom((int) $release->source) ?? ReleaseSource::Unknown;

            return new TvReleaseRow(
                id: (int) $release->id,
                guid: $row->guid,
                name: $row->name,
                showId: $showId,
                showTitle: $show === null ? '' : $show->title,
                poster: $show?->artwork,
                episodeLabel: $episodeLabel,
                showUrl: $showUrl,
                resolution: ReleaseResolution::tryFrom((int) $release->resolution) ?? ReleaseResolution::Unknown,
                source: $source->label(),
                size: self::size((float) $release->size),
                files: $row->files,
                date: $this->date($sortDate, $now),
                dateTitle: 'Posted '.userDate($release->postdate, 'M j, Y, g:i A').' · Added '.userDate($release->adddate, 'M j, Y, g:i A'),
                day: $sortDate === null ? '' : CarbonImmutable::parse($sortDate, config('app.timezone', 'UTC'))->toDateString(),
                grabs: $row->grabs,
                comments: $row->comments,
                completion: $completion,
                passworded: $row->passworded,
                mediaInfo: $row->has_media_info ? $this->mediaInfo($row->media_info_summary) : null,
                nfo: $row->nfo,
                preview: (int) $release->haspreview === 1 ? ['thumb' => getImageAssetUrl('preview', $row->guid.'_thumb'), 'full' => getImageAssetUrl('preview', $row->guid)] : null,
                sample: (int) $release->jpgstatus === 1 ? ['thumb' => getImageAssetUrl('sample', $row->guid.'_thumb'), 'full' => getImageAssetUrl('sample', $row->guid)] : null,
                inCart: $row->in_basket,
                watched: $row->watched,
            );
        }, $ordered);
    }

    /** Sizes as the prototype writes them: 2.41 GB, 12.3 GB, 734 MB, 912 KB. */
    public static function size(float $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => number_format($bytes / 1073741824, $bytes >= 10737418240 ? 1 : 2).' GB',
            $bytes >= 1048576 => number_format($bytes / 1048576).' MB',
            default => max(1, (int) round($bytes / 1024)).' KB',
        };
    }

    /** Codec and audio in plain words: "V_MPEGH/ISO/HEVC · E-AC-3 6" reads "H.265 · E-AC-3 5.1". */
    private function mediaInfo(?string $summary): string
    {
        $parts = array_values(array_filter(explode(' · ', (string) $summary), static fn (string $part): bool => $part !== '' && preg_match('/^\d+p$/', $part) !== 1));
        $parts = array_map(static function (string $part): string {
            if (isset(self::CODECS[strtoupper($part)])) {
                return self::CODECS[strtoupper($part)];
            }

            return preg_replace_callback('/^(.*\S)\s+(\d+)$/', static fn (array $match): string => $match[1].' '.(self::CHANNELS[$match[2]] ?? $match[2]), $part) ?? $part;
        }, $parts);

        return $parts === [] ? 'Media info' : implode(' · ', $parts);
    }

    private function date(?string $value, CarbonImmutable $now): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $date = CarbonImmutable::parse($value, config('app.timezone', 'UTC'));
        $minutes = max(1, (int) round(($now->getTimestamp() - $date->getTimestamp()) / 60));

        return match (true) {
            $minutes < 60 => $minutes.' min ago',
            $minutes < 1440 => (int) round($minutes / 60).' hr ago',
            default => userDate($value, 'M j, Y'),
        };
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
