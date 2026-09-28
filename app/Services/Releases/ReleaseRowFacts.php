<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseRowData;
use App\Enums\ReleaseResolution;
use App\Enums\ReleaseSource;
use App\Support\ReleaseCompletion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The facts every section's release rows show (the TV and Movies release tables): the shared
 * row loader's data read in display order, and each release's chips, sizes and dates in the
 * redesigned screens' formats. The sections add their title (show or film) to these.
 *
 * @phpstan-type RowFacts array{id: int, guid: string, name: string, resolution: ReleaseResolution, source: string, size: string, files: int, date: string, dateTitle: string, day: string, grabs: int, comments: int, completion: array{percent: int, band: string, repairing: bool}|null, passworded: bool, mediaInfo: ?string, nfo: bool, preview: array{thumb: ?string, full: ?string}|null, sample: array{thumb: ?string, full: ?string}|null, inCart: bool, watched: bool, bytes: float, postedAt: int, postedOn: string, group: string, uploader: string}
 */
final class ReleaseRowFacts
{
    private const CODECS = [
        'V_MPEG4/ISO/AVC' => 'H.264', 'AVC' => 'H.264', 'X264' => 'H.264', 'H264' => 'H.264',
        'V_MPEGH/ISO/HEVC' => 'H.265', 'HEVC' => 'H.265', 'X265' => 'H.265', 'H265' => 'H.265',
        'V_AV1' => 'AV1', 'AV1' => 'AV1', 'V_MPEG2' => 'MPEG-2', 'MPEG VIDEO' => 'MPEG-2',
    ];

    private const CHANNELS = ['1' => '1.0', '2' => '2.0', '6' => '5.1', '8' => '7.1'];

    /** The chip's words when media info exists but has no codec or audio to name. */
    public const MEDIA_INFO_FALLBACK = 'Media info';

    public function __construct(private readonly ReleaseBrowseService $releases) {}

    /**
     * The releases in display order with the shared row data (`row_data`); ids that no longer
     * exist are left out.
     *
     * @param  list<int>  $ids  in display order
     * @param  list<string>  $columns  further `releases` columns the caller reads
     * @return list<object>
     */
    public function load(array $ids, array $columns = []): array
    {
        if ($ids === []) {
            return [];
        }
        $releases = DB::table('releases')->whereIn('id', $ids)
            ->get(array_values(array_unique([...ReleaseRowDataLoader::REQUIRED_COLUMNS, 'resolution', 'source', ...$columns])))->keyBy('id');
        $ordered = [];
        foreach ($ids as $id) {
            if ($releases->has($id)) {
                $ordered[] = $releases->get($id);
            }
        }

        return array_values($this->releases->loadReleaseRows($ordered));
    }

    /**
     * One loaded release's facts, as named constructor arguments of a row.
     *
     * @return RowFacts
     */
    public function facts(object $release, bool $byAdded, CarbonImmutable $now): array
    {
        /** @var ReleaseRowData $row */
        $row = $release->row_data;
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

        return [
            'id' => (int) $release->id,
            'guid' => $row->guid,
            'name' => $row->name,
            'resolution' => ReleaseResolution::tryFrom((int) $release->resolution) ?? ReleaseResolution::Unknown,
            'source' => $source->label(),
            'size' => self::size((float) $release->size),
            'files' => $row->files,
            'date' => self::date($sortDate, $now),
            'dateTitle' => 'Posted '.userDate($release->postdate, 'M j, Y, g:i A').' · Added '.userDate($release->adddate, 'M j, Y, g:i A'),
            'day' => $sortDate === null ? '' : CarbonImmutable::parse($sortDate, config('app.timezone', 'UTC'))->toDateString(),
            'grabs' => $row->grabs,
            'comments' => $row->comments,
            'completion' => $completion,
            'passworded' => $row->passworded,
            'mediaInfo' => $row->has_media_info ? $this->mediaInfo($row->media_info_summary) : null,
            'nfo' => $row->nfo,
            'preview' => (int) $release->haspreview === 1 ? ['thumb' => getImageAssetUrl('preview', $row->guid.'_thumb'), 'full' => getImageAssetUrl('preview', $row->guid)] : null,
            'sample' => (int) $release->jpgstatus === 1 ? ['thumb' => getImageAssetUrl('sample', $row->guid.'_thumb'), 'full' => getImageAssetUrl('sample', $row->guid)] : null,
            'inCart' => $row->in_basket,
            'watched' => $row->watched,
            'bytes' => (float) $release->size,
            'postedAt' => $release->postdate === null ? 0 : CarbonImmutable::parse($release->postdate, config('app.timezone', 'UTC'))->getTimestamp(),
            'postedOn' => $release->postdate === null ? '' : userDate($release->postdate, 'M j, Y'),
            'group' => $row->group,
            'uploader' => $row->poster,
        ];
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

        return $parts === [] ? self::MEDIA_INFO_FALLBACK : implode(' · ', $parts);
    }

    /** A date as the redesigned screens show it: "12 min ago", "2 hr ago" under a day old, then `Sep 16, 2026`. */
    public static function date(?string $value, CarbonImmutable $now): string
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
}
