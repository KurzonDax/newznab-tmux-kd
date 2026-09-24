<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\ReleaseResolution;
use App\Enums\ReleaseSource;
use App\Services\MediaInfo\Enums\MediaInfoSourceCompleteness;
use App\Services\MediaInfo\MediaInfoSnapshotService;
use App\Support\ReleaseQuality;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the per-release facts derived from other release data (`resolution`, `source`)
 * in step. Called only from SearchService::updateRelease(), which every release change
 * already reaches.
 */
final class ReleaseDerivedFacts
{
    public function __construct(private readonly MediaInfoSnapshotService $snapshots) {}

    public function refresh(int $releaseId): void
    {
        $release = DB::table('releases')->where('id', $releaseId)->first(['searchname', 'resolution', 'source']);
        if ($release === null) {
            return;
        }

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
