<?php

declare(strict_types=1);

namespace Tests\Feature\Releases;

use App\Models\Release;
use App\Services\Releases\ReleaseMediaInfoAvailabilityLoader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class ReleaseMediaInfoAvailabilityLoaderTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /** @return array<string, string> */
    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '0'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        foreach ([
            'media_info_probes' => ['id', 'releases_id', 'embedded_title', 'source_filename', 'container_format', 'music_tags', 'duration_ms', 'overall_bitrate_bps'],
            'media_infos' => ['id', 'releases_id', 'movie_name', 'file_name'],
            'video_data' => ['releases_id', 'containerformat', 'overallbitrate', 'videoduration', 'videoformat', 'videocodec', 'videoaspect', 'videolibrary', 'videowidth', 'videoheight', 'videoframerate'],
            'audio_data' => ['id', 'releases_id', 'audioformat', 'audiobitrate', 'audiochannels', 'audiosamplerate', 'audiolanguage', 'audiotitle', 'audioid'],
            'release_subtitles' => ['id', 'releases_id', 'subslanguage', 'subsid'],
            'release_audio_tags' => ['id', 'releases_id', 'album', 'performer', 'album_performer', 'genre', 'recorded_date', 'track_name', 'musicbrainz_album_id', 'musicbrainz_track_id', 'audio_format', 'track_position', 'track_position_total'],
        ] as $tableName => $columns) {
            ProductionTables::fromAuthority()->create($tableName, $columns);
        }
        Schema::create('media_info_tracks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('media_info_probe_id');
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_it_marks_snapshot_audio_only_legacy_and_absent_rows_without_per_release_queries(): void
    {
        DB::table('media_info_probes')->insert(['releases_id' => 1, 'embedded_title' => 'Embedded title']);
        DB::table('audio_data')->insert(['releases_id' => 2, 'audioformat' => 'FLAC']);
        DB::table('release_subtitles')->insert(['releases_id' => 3, 'subslanguage' => 'English']);
        DB::table('release_audio_tags')->insert(['releases_id' => 4, 'album' => 'Tagged album']);
        DB::table('release_audio_tags')->insert(['releases_id' => 5, 'genre' => 'Jazz']);
        $trackOnlyProbeId = DB::table('media_info_probes')->insertGetId(['releases_id' => 7]);
        DB::table('media_info_tracks')->insert(['media_info_probe_id' => $trackOnlyProbeId]);
        DB::table('release_audio_tags')->insert(['releases_id' => 8]);
        $rows = array_map(static function (int $id): stdClass {
            $row = new stdClass;
            $row->id = $id;

            return $row;
        }, range(1, 8));
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        (new ReleaseMediaInfoAvailabilityLoader)->load($rows);

        self::assertSame([true, true, true, true, true, false, true, false], array_map(
            static fn (stdClass $row): bool => $row->has_media_info,
            $rows,
        ));
        self::assertLessThanOrEqual(9, $queries, 'Availability queries are bounded by source tables, not release count.');
    }

    /**
     * The chip's words (adult-redesign SPEC 5B.7, DATA-CONTRACT test 14): the video by its format
     * first and its codec id second, the audio by the media info dialog's short name, both from
     * MediaInfoNames; a codec-first lookup would read the stream numbers and four-character ids.
     */
    public function test_video_summaries_include_height_and_name_the_format_first_and_the_codec_id_second(): void
    {
        DB::table('video_data')->insert([
            ['releases_id' => 1, 'videoheight' => 1080, 'videocodec' => 'x264', 'videoformat' => 'AVC'],
            ['releases_id' => 2, 'videoheight' => 2160, 'videocodec' => null, 'videoformat' => 'HEVC'],
            ['releases_id' => 3, 'videoheight' => 0, 'videocodec' => '27', 'videoformat' => 'AVC'],
            ['releases_id' => 4, 'videoheight' => 0, 'videocodec' => 'hvc1', 'videoformat' => 'HEVC'],
            ['releases_id' => 5, 'videoheight' => 0, 'videocodec' => 'avc1', 'videoformat' => 'AVC'],
            ['releases_id' => 6, 'videoheight' => 0, 'videocodec' => 'V_MPEG4/ISO/AVC', 'videoformat' => ''],
            ['releases_id' => 7, 'videoheight' => 0, 'videocodec' => 'WMV3', 'videoformat' => 'VC-1'],
            ['releases_id' => 9, 'videoheight' => 0, 'videocodec' => 'WVC1', 'videoformat' => ''],
        ]);
        DB::table('audio_data')->insert([
            ['releases_id' => 5, 'audioformat' => 'AAC LC', 'audiochannels' => '2'],
            ['releases_id' => 7, 'audioformat' => 'MLP FBA 16-ch', 'audiochannels' => '8'],
            ['releases_id' => 8, 'audioformat' => 'Vorbis', 'audiochannels' => '2'],
        ]);
        $rows = [(object) ['id' => 1], (new Release)->setRawAttributes(['id' => 2]), ...array_map(static fn (int $id): object => (object) ['id' => $id], range(3, 9))];

        (new ReleaseMediaInfoAvailabilityLoader)->load($rows);

        self::assertSame(['1080p · H.264', '2160p · H.265', 'H.264', 'H.265', 'H.264 · AAC 2', 'H.264', 'VC-1 · TrueHD Atmos 8', 'Vorbis 2', 'WVC1'],
            array_map(static fn (object $row): ?string => $row->media_info_summary, $rows));
        self::assertTrue($rows[0]->has_media_info);
        self::assertTrue($rows[1]->has_media_info);
    }

    public function test_summaries_load_one_video_and_the_earliest_audio_while_availability_checks_all_streams(): void
    {
        foreach ([1, 2] as $releaseId) {
            DB::table('video_data')->insert([
                'releases_id' => $releaseId,
                'videoformat' => $releaseId === 1 ? 'AVC' : null,
            ]);
            $audios = [];
            foreach (range(1000, 1) as $stream) {
                $first = $stream === 1;
                $id = ($releaseId - 1) * 1000 + $stream;
                $audios[] = [
                    'id' => $id, 'releases_id' => $releaseId,
                    'audioformat' => $first ? null : 'DTS',
                    'audiochannels' => $first ? null : '5.1',
                ];
            }
            foreach (array_chunk($audios, 100) as $chunk) {
                DB::table('audio_data')->insert($chunk);
            }
        }
        $rows = [(object) ['id' => 1], (new Release)->setRawAttributes(['id' => 2])];
        $queries = [];
        $listening = true;
        DB::listen(static function ($query) use (&$queries, &$listening): void {
            if ($listening && ! str_contains($query->sql, 'union')
                && (str_contains($query->sql, 'from "video_data"') || str_contains($query->sql, 'from "audio_data"'))) {
                $queries[] = $query;
            }
        });

        (new ReleaseMediaInfoAvailabilityLoader)->load($rows);
        $listening = false;

        self::assertSame('H.264', $rows[0]->media_info_summary);
        self::assertNull($rows[1]->media_info_summary);
        self::assertTrue($rows[0]->has_media_info);
        self::assertTrue($rows[1]->has_media_info, 'A later stream still makes media info available.');
        self::assertCount(2, $queries);
        foreach ($queries as $query) {
            self::assertStringNotContainsString('*', $query->sql);
            $records = DB::select($query->sql, $query->bindings);
            self::assertCount(2, $records);
            self::assertSame([1, 2], array_column($records, 'releases_id'));
            self::assertSame(
                str_contains($query->sql, 'video_data')
                    ? ['releases_id', 'videoheight', 'videocodec', 'videoformat']
                    : ['releases_id', 'audioformat', 'audiochannels'],
                array_keys((array) $records[0]),
            );
        }
    }
}
