<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReleaseResolution as R;
use App\Enums\ReleaseSource as S;
use App\Services\Releases\ReleaseDerivedFacts;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/** The migration's fill statement and the PHP rule must give every release the same values. */
final class ReleaseQualityFillTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // SQLite has no REGEXP functions; give it PCRE ones so the MariaDB statement runs unchanged.
        $this->registerSqliteFunction('regexp', fn (string $pattern, ?string $subject): int => (int) preg_match('~'.$pattern.'~', (string) $subject), 2);
        $this->registerSqliteFunction('regexp_substr', fn (?string $subject, string $pattern): string => preg_match('~'.$pattern.'~', (string) $subject, $match) ? $match[0] : '', 2);
        $tables = ProductionTables::fromAuthority();
        $tables->create('releases', ['id', 'searchname', 'categories_id', 'resolution', 'source']);
        $tables->create('video_data', ['releases_id', 'videowidth', 'videoheight']);
        $tables->create('media_info_probes');
        $tables->create('media_info_tracks');
    }

    public function test_the_fill_statement_and_the_php_rule_agree(): void
    {
        $expected = [];
        $probeId = 0;
        foreach ($this->fixtures() as $id => [$name, $videoData, $probes, $resolution, $source]) {
            DB::table('releases')->insert(['id' => $id, 'searchname' => $name, 'categories_id' => 5040, 'resolution' => 0, 'source' => 0]);
            if ($videoData !== null) {
                DB::table('video_data')->insert(['releases_id' => $id, 'videowidth' => $videoData[0], 'videoheight' => $videoData[1]]);
            }
            foreach ($probes as [$capturedAt, $completeness, $tracks]) {
                DB::table('media_info_probes')->insert([
                    'id' => ++$probeId, 'releases_id' => $id, 'captured_at' => $capturedAt, 'source_kind' => 'sample',
                    'source_completeness' => $completeness, 'schema_version' => 1,
                    'diagnostic_filtered' => 0, 'diagnostic_truncated' => 0,
                ]);
                foreach ($tracks as $trackIndex => [$type, $width, $height]) {
                    DB::table('media_info_tracks')->insert([
                        'media_info_probe_id' => $probeId, 'type' => $type, 'track_index' => $trackIndex,
                        'width' => $width, 'height' => $height, 'diagnostic_filtered' => 0, 'diagnostic_truncated' => 0,
                    ]);
                }
            }
            $expected[$id] = [$resolution->value, $source->value];
        }

        $facts = app(ReleaseDerivedFacts::class);
        $facts->fillAll();
        $filled = $this->stored();

        DB::table('releases')->update(['resolution' => 0, 'source' => 0]);
        foreach (array_keys($expected) as $id) {
            $facts->refresh($id);
        }

        $this->assertSame($expected, $filled, 'fill statement');
        $this->assertSame($expected, $this->stored(), 'PHP rule');
    }

    /** @return array<int, array{int, int}> */
    private function stored(): array
    {
        return DB::table('releases')->orderBy('id')->get(['id', 'resolution', 'source'])
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => [(int) $row->resolution, (int) $row->source]])->all();
    }

    /** @return array<int, array{string, ?array{?int, ?int}, list<array{string, string, list<array{string, ?int, ?int}>}>, R, S}> */
    private function fixtures(): array
    {
        $video = fn (?int $width, ?int $height): array => ['video', $width, $height];

        return [
            1 => ['Show.S01E01.2160p.WEB-DL-GRP', null, [], R::Uhd, S::Web],
            2 => ['Show.S01E01.1080i.HDTV', null, [], R::FullHd, S::Hdtv],
            3 => ['SHOW.S01E01.720P.PDTV', null, [], R::Hd, S::Hdtv],
            4 => ['Show.576p.DVDRip', null, [], R::Sd, S::Dvd],
            5 => ['Show.480i.DVD.x264', null, [], R::Sd, S::Dvd],
            6 => ['Show.720p.from.1080p.Blu-Ray', null, [], R::Hd, S::BluRay],
            7 => ['Movie.BluRay.1080p.REMUX', null, [], R::FullHd, S::Remux],
            8 => ['Movie.BDRemux', null, [], R::Unknown, S::Remux],
            9 => ['Show.HDTV.WEBRip', null, [], R::Unknown, S::Web],
            10 => ['Some.Album.FLAC', null, [], R::Unknown, S::Unknown],
            11 => ['Show.x1080p.WEBX.DVD9', null, [], R::Unknown, S::Unknown],
            // Measured beats the name; zeros are not a measurement.
            12 => ['Show.S01E01.2160p', [1280, 720], [], R::Hd, S::Unknown],
            13 => ['Show.S01E01.1080p', [0, 0], [], R::FullHd, S::Unknown],
            14 => ['Show.S01E01.1080p', [null, null], [], R::FullHd, S::Unknown],
            15 => ['Show.S01E01', [3000, 2100], [], R::Uhd, S::Unknown],
            16 => ['Show.S01E01', [1920, 800], [], R::FullHd, S::Unknown],
            17 => ['Show.S01E01', [640, 0], [], R::Sd, S::Unknown],
            18 => ['Show.S01E01', [-1, 0], [], R::Unknown, S::Unknown],
            19 => ['Show.1080p.DSR', [720, 576], [['2026-01-02 00:00:00', 'partial', [$video(3840, 2160)]]], R::Uhd, S::Hdtv],
            // A Complete probe beats a newer Partial one, but only among the newest two.
            20 => ['Show.TVRip', null, [
                ['2026-01-01 00:00:00', 'complete', [$video(1920, 1080)]],
                ['2026-01-02 00:00:00', 'partial', [$video(1280, 720)]],
            ], R::FullHd, S::Hdtv],
            21 => ['Show.SDTV', null, [
                ['2026-01-01 00:00:00', 'complete', [$video(3840, 2160)]],
                ['2026-01-02 00:00:00', 'partial', [$video(720, 576)]],
                ['2026-01-03 00:00:00', 'partial', [$video(1920, 1080)]],
            ], R::FullHd, S::Hdtv],
            22 => ['Show', null, [
                ['2026-01-01 00:00:00', 'partial', [$video(720, 576)]],
                ['2026-01-01 00:00:00', 'partial', [$video(1280, 720)]],
            ], R::Hd, S::Unknown],
            23 => ['Show.1080p', null, [['2026-01-01 00:00:00', 'complete', [['audio', null, null], $video(0, 0), $video(1280, 720)]]], R::Hd, S::Unknown],
            24 => ['Show.1080p', [720, 576], [['2026-01-01 00:00:00', 'complete', [['audio', null, null], $video(0, null)]]], R::Sd, S::Unknown],
            25 => ['Show.2160p', null, [['2026-01-01 00:00:00', 'unknown', [$video(null, null)]]], R::Uhd, S::Unknown],
        ];
    }
}
