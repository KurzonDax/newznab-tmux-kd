<?php

declare(strict_types=1);

namespace Tests\Feature\Releases;

use App\Services\Releases\ReleaseMediaInfoAvailabilityLoader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;
use Tests\Support\IsolatedSqliteDatabase;
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
        foreach (['media_info_probes', 'media_infos', 'video_data', 'audio_data', 'release_subtitles', 'release_audio_tags'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
                $table->id();
                $table->unsignedBigInteger('releases_id');
                foreach (match ($tableName) {
                    'media_info_probes' => ['embedded_title', 'source_filename', 'container_format', 'music_tags'],
                    'media_infos' => ['movie_name', 'file_name'],
                    'video_data' => ['containerformat', 'overallbitrate', 'videoduration', 'videoformat', 'videocodec', 'videoaspect', 'videolibrary'],
                    'audio_data' => ['audioformat', 'audiobitrate', 'audiochannels', 'audiosamplerate', 'audiolanguage', 'audiotitle'],
                    'release_subtitles' => ['subslanguage'],
                    'release_audio_tags' => ['album', 'performer', 'album_performer', 'genre', 'recorded_date', 'track_name', 'musicbrainz_album_id', 'musicbrainz_track_id', 'audio_format'],
                } as $column) {
                    $table->string($column)->nullable();
                }
                foreach (match ($tableName) {
                    'media_info_probes' => ['duration_ms', 'overall_bitrate_bps'],
                    'video_data' => ['videowidth', 'videoheight', 'videoframerate'],
                    'audio_data' => ['audioid'],
                    'release_subtitles' => ['subsid'],
                    'release_audio_tags' => ['track_position', 'track_position_total'],
                    default => [],
                } as $column) {
                    $table->unsignedBigInteger($column)->nullable();
                }
            });
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
        self::assertLessThanOrEqual(7, $queries, 'Availability queries are bounded by source tables, not release count.');
    }
}
