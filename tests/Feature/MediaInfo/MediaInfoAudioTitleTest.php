<?php

declare(strict_types=1);

namespace Tests\Feature\MediaInfo;

use App\Models\Release;
use App\Services\MediaInfo\MediaInfoPresentationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The media info payload tells the block whether the release is in the Audio band, so the
 * audio table can lead with Title instead of Language (docs/proposals/audio-redesign/SPEC.md 5C.3).
 */
class MediaInfoAudioTitleTest extends TestCase
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
        $tables = ProductionTables::fromAuthority();
        $tables->create('releases', ['id', 'searchname', 'display_name', 'resolution', 'categories_id']);
        foreach (['media_info_probes', 'media_info_tracks', 'media_infos', 'video_data', 'audio_data', 'release_subtitles', 'release_audio_tags'] as $table) {
            $tables->create($table);
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_a_lossless_release_with_legacy_rows_is_in_the_audio_band_and_carries_the_tag_title(): void
    {
        $release = $this->legacyRelease(3040);
        DB::table('release_audio_tags')->insert(['releases_id' => $release->id, 'track_name' => 'After the Rain']);

        $media = (new MediaInfoPresentationService)->forRelease($release)['media'];

        self::assertTrue($media['in_audio_band']);
        self::assertSame('After the Rain', $media['music_tags']['track_title']);
    }

    public function test_an_audio_other_release_with_a_snapshot_is_in_the_audio_band(): void
    {
        $release = $this->release(3999);
        $probeId = DB::table('media_info_probes')->insertGetId([
            'releases_id' => $release->id,
            'captured_at' => '2026-10-04 10:00:00',
            'source_kind' => 'additional-processing',
            'source_completeness' => 'complete',
            'schema_version' => 1,
            'container_format' => 'FLAC',
            'music_tags' => json_encode(['track_title' => 'Snapshot Track']),
            'diagnostic_filtered' => 0,
            'diagnostic_truncated' => 0,
        ]);
        DB::table('media_info_tracks')->insert([
            'media_info_probe_id' => $probeId,
            'type' => 'audio',
            'track_index' => 0,
            'format' => 'FLAC',
            'diagnostic_filtered' => 0,
            'diagnostic_truncated' => 0,
        ]);

        $media = (new MediaInfoPresentationService)->forRelease($release)['media'];

        self::assertTrue($media['in_audio_band']);
        self::assertSame('Snapshot Track', $media['music_tags']['track_title']);
    }

    public function test_a_movies_release_is_not_in_the_audio_band(): void
    {
        $release = $this->legacyRelease(2040);

        self::assertFalse((new MediaInfoPresentationService)->forRelease($release)['media']['in_audio_band']);
    }

    public function test_an_audio_release_without_media_info_keeps_the_empty_shape(): void
    {
        $release = $this->release(3040);

        self::assertSame([
            'release_name' => 'Release.3040',
            'resolution' => null,
            'media' => null,
        ], (new MediaInfoPresentationService)->forRelease($release));
    }

    public function test_the_flag_costs_no_extra_query(): void
    {
        $audio = $this->legacyRelease(3040);
        $movie = $this->legacyRelease(2040);
        $service = new MediaInfoPresentationService;

        self::assertSame($this->queriesFor($service, $movie), $this->queriesFor($service, $audio));
    }

    public function test_the_web_endpoint_carries_the_flag(): void
    {
        $this->withoutMiddleware();
        $audio = $this->legacyRelease(3010);
        $movie = $this->legacyRelease(2040);

        $this->getJson(route('release.mediainfo', ['release' => $audio->id]))
            ->assertOk()
            ->assertJsonPath('media.in_audio_band', true);
        $this->getJson(route('release.mediainfo', ['release' => $movie->id]))
            ->assertOk()
            ->assertJsonPath('media.in_audio_band', false);
    }

    private function queriesFor(MediaInfoPresentationService $service, Release $release): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->forRelease($release);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function legacyRelease(int $categoryId): Release
    {
        $release = $this->release($categoryId);
        DB::table('audio_data')->insert([
            'releases_id' => $release->id,
            'audioid' => 1,
            'audioformat' => 'FLAC',
            'audiochannels' => '2',
        ]);

        return $release;
    }

    private function release(int $categoryId): Release
    {
        $id = DB::table('releases')->insertGetId([
            'searchname' => 'Release.'.$categoryId,
            'display_name' => null,
            'resolution' => 0,
            'categories_id' => $categoryId,
        ]);

        return Release::query()->findOrFail($id);
    }
}
