<?php

declare(strict_types=1);

namespace Tests\Feature\MediaInfo;

use App\Models\Release;
use App\Services\MediaInfo\MediaInfoPresentationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class MediaInfoPresentationServiceTest extends TestCase
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
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_selected_snapshot_is_presented_without_diagnostics_or_legacy_mixing(): void
    {
        $release = $this->release('<script>alert("release")</script>', 'Readable Release');
        $probeId = DB::table('media_info_probes')->insertGetId([
            'releases_id' => $release->id,
            'captured_at' => '2026-09-06 10:00:00',
            'source_kind' => 'additional-processing',
            'source_filename' => 'movie.mkv',
            'source_completeness' => 'complete',
            'schema_version' => 1,
            'embedded_title' => '<b>Embedded title</b>',
            'container_format' => 'Matroska',
            'duration_ms' => 6_480_000,
            'overall_bitrate_bps' => 18_600_000,
            'music_tags' => json_encode(['album' => 'Snapshot Album']),
            'diagnostic_raw' => json_encode(['secret' => 'never expose']),
            'diagnostic_filtered' => false,
            'diagnostic_truncated' => false,
        ]);
        DB::table('media_info_tracks')->insert([
            'media_info_probe_id' => $probeId,
            'type' => 'video',
            'track_index' => 0,
            'source_id' => '1',
            'title' => 'Main feature',
            'format' => 'HEVC',
            'width' => 3840,
            'height' => 2160,
            'is_default' => true,
            'is_forced' => null,
            'diagnostic_raw' => json_encode(['path' => '/private/movie.mkv']),
            'diagnostic_filtered' => true,
            'diagnostic_truncated' => false,
        ]);
        DB::table('video_data')->insert([
            'releases_id' => $release->id,
            'containerformat' => 'Legacy AVI',
            'videoformat' => 'DIVX',
        ]);

        $payload = (new MediaInfoPresentationService)->forRelease($release);

        self::assertSame('Readable Release', $payload['release_name']);
        self::assertSame('Embedded movie title', $payload['media']['identity']['label']);
        self::assertSame('<b>Embedded title</b>', $payload['media']['identity']['title']);
        self::assertSame('Matroska', $payload['media']['container']['format']);
        self::assertSame('HEVC', $payload['media']['streams']['video'][0]['format']);
        self::assertSame([], $payload['media']['streams']['audio']);
        self::assertStringNotContainsString('diagnostic', json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Legacy AVI', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_legacy_fallback_preserves_audio_tags_stream_titles_and_individual_subtitles(): void
    {
        $release = $this->release('Legacy.Search.Name', null);
        DB::table('media_infos')->insert([
            'releases_id' => $release->id,
            'movie_name' => null,
            'file_name' => 'legacy-file.flac',
        ]);
        DB::table('audio_data')->insert([
            [
                'releases_id' => $release->id,
                'audioid' => 2,
                'audioformat' => 'FLAC',
                'audiochannels' => '2',
                'audiobitrate' => '2780 kb/s',
                'audiolanguage' => 'English',
                'audiosamplerate' => '96.0 kHz',
                'audiotitle' => 'After the Rain',
            ],
            [
                'releases_id' => $release->id,
                'audioid' => 2,
                'audioformat' => 'AAC',
                'audiochannels' => null,
                'audiobitrate' => null,
                'audiolanguage' => null,
                'audiosamplerate' => null,
                'audiotitle' => 'Commentary',
            ],
        ]);
        DB::table('release_subtitles')->insert([
            ['releases_id' => $release->id, 'subsid' => 4, 'subslanguage' => 'English'],
            ['releases_id' => $release->id, 'subsid' => 5, 'subslanguage' => 'Spanish'],
        ]);
        DB::table('release_audio_tags')->insert([
            'releases_id' => $release->id,
            'album' => 'Night Windows',
            'performer' => 'Northbound Quartet',
            'album_performer' => 'Northbound Quartet',
            'track_name' => 'After the Rain',
            'track_position' => 3,
            'track_position_total' => 9,
            'musicbrainz_album_id' => 'release-id',
            'musicbrainz_track_id' => 'recording-id',
        ]);

        $payload = (new MediaInfoPresentationService)->forRelease($release);

        self::assertSame('Legacy.Search.Name', $payload['release_name']);
        self::assertSame('Embedded track title', $payload['media']['identity']['label']);
        self::assertSame('After the Rain', $payload['media']['identity']['title']);
        self::assertSame('legacy-file.flac', $payload['media']['container']['source_filename']);
        self::assertCount(2, $payload['media']['streams']['audio']);
        self::assertSame('After the Rain', $payload['media']['streams']['audio'][0]['title']);
        self::assertSame('Commentary', $payload['media']['streams']['audio'][1]['title']);
        self::assertCount(2, $payload['media']['streams']['subtitle']);
        self::assertNull($payload['media']['streams']['subtitle'][0]['forced']);
        self::assertNull($payload['media']['streams']['subtitle'][0]['default']);
        self::assertSame('release-id', $payload['media']['music_tags']['musicbrainz_release_id']);
        self::assertSame('recording-id', $payload['media']['music_tags']['musicbrainz_recording_id']);
    }

    public function test_snapshot_music_identity_uses_the_track_tag_instead_of_the_container_title(): void
    {
        $release = $this->release('Music.Release', null);
        DB::table('media_info_probes')->insert([
            'releases_id' => $release->id,
            'captured_at' => '2026-09-06 10:00:00',
            'source_kind' => 'audio-processing',
            'source_filename' => 'track.flac',
            'source_completeness' => 'complete',
            'schema_version' => 1,
            'embedded_title' => 'Container title',
            'music_tags' => json_encode(['track_title' => 'Tagged track title']),
            'diagnostic_filtered' => false,
            'diagnostic_truncated' => false,
        ]);

        $payload = (new MediaInfoPresentationService)->forRelease($release);

        self::assertSame('Embedded track title', $payload['media']['identity']['label']);
        self::assertSame('Tagged track title', $payload['media']['identity']['title']);
    }

    public function test_release_without_snapshot_or_legacy_metadata_returns_the_defensive_empty_shape(): void
    {
        $release = $this->release('Empty.Release', null);

        self::assertSame([
            'release_name' => 'Empty.Release',
            'media' => null,
        ], (new MediaInfoPresentationService)->forRelease($release));
    }

    public function test_web_endpoint_returns_the_curated_shape_without_changing_the_public_api_route(): void
    {
        $this->withoutMiddleware();
        $release = $this->release('Web.Release', 'Web Release');
        DB::table('audio_data')->insert([
            'releases_id' => $release->id,
            'audioid' => 1,
            'audioformat' => 'FLAC',
            'audiotitle' => 'Track title',
        ]);

        $this->getJson(route('release.mediainfo', ['release' => $release->id]))
            ->assertOk()
            ->assertJsonPath('release_name', 'Web Release')
            ->assertJsonPath('media.streams.audio.0.title', 'Track title')
            ->assertJsonMissingPath('media.provenance')
            ->assertJsonMissingPath('media.diagnostic_raw');

        self::assertTrue(collect(Route::getRoutes())->contains(
            static fn (\Illuminate\Routing\Route $route): bool => $route->uri() === 'api/release/{id}/mediainfo',
        ));
        self::assertEqualsCanonicalizing(
            ['web', 'auth', 'isVerified', '2fa', 'throttle:60,1'],
            Route::getRoutes()->getByName('release.mediainfo')?->gatherMiddleware(),
        );
    }

    private function release(string $searchName, ?string $displayName): Release
    {
        $id = DB::table('releases')->insertGetId([
            'searchname' => $searchName,
            'display_name' => $displayName,
        ]);

        return Release::query()->findOrFail($id);
    }

    private function createSchema(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('searchname');
            $table->string('display_name')->nullable();
        });
        Schema::create('media_info_probes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('releases_id');
            $table->dateTime('captured_at');
            $table->string('source_kind');
            $table->string('source_filename')->nullable();
            $table->string('source_completeness');
            $table->unsignedSmallInteger('schema_version');
            $table->string('embedded_title')->nullable();
            $table->string('container_format')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('overall_bitrate_bps')->nullable();
            $table->json('music_tags')->nullable();
            $table->json('diagnostic_raw')->nullable();
            $table->boolean('diagnostic_filtered')->default(false);
            $table->boolean('diagnostic_truncated')->default(false);
            $table->timestamps();
        });
        Schema::create('media_info_tracks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('media_info_probe_id');
            $table->string('type');
            $table->unsignedSmallInteger('track_index');
            $table->string('source_id')->nullable();
            $table->string('stream_order')->nullable();
            $table->string('title')->nullable();
            $table->string('language')->nullable();
            $table->string('format')->nullable();
            $table->string('codec')->nullable();
            $table->boolean('is_default')->nullable();
            $table->boolean('is_forced')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('bitrate_bps')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('aspect_ratio')->nullable();
            $table->decimal('frame_rate', 10, 3)->nullable();
            $table->string('profile')->nullable();
            $table->unsignedSmallInteger('bit_depth')->nullable();
            $table->string('hdr_format')->nullable();
            $table->string('color_primaries')->nullable();
            $table->string('transfer_characteristics')->nullable();
            $table->string('matrix_coefficients')->nullable();
            $table->unsignedSmallInteger('channels')->nullable();
            $table->string('channel_layout')->nullable();
            $table->unsignedInteger('sample_rate_hz')->nullable();
            $table->json('diagnostic_raw')->nullable();
            $table->boolean('diagnostic_filtered')->default(false);
            $table->boolean('diagnostic_truncated')->default(false);
            $table->timestamps();
        });
        Schema::create('media_infos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('releases_id');
            $table->string('movie_name')->nullable();
            $table->string('file_name')->nullable();
        });
        Schema::create('video_data', function (Blueprint $table): void {
            $table->unsignedBigInteger('releases_id');
            $table->string('containerformat')->nullable();
            $table->string('overallbitrate')->nullable();
            $table->string('videoduration')->nullable();
            $table->string('videoformat')->nullable();
            $table->string('videocodec')->nullable();
            $table->unsignedInteger('videowidth')->nullable();
            $table->unsignedInteger('videoheight')->nullable();
            $table->string('videoaspect')->nullable();
            $table->decimal('videoframerate', 10, 3)->nullable();
            $table->string('videolibrary')->nullable();
        });
        Schema::create('audio_data', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('releases_id');
            $table->unsignedInteger('audioid')->nullable();
            $table->string('audioformat')->nullable();
            $table->string('audiochannels')->nullable();
            $table->string('audiobitrate')->nullable();
            $table->string('audiolanguage')->nullable();
            $table->string('audiosamplerate')->nullable();
            $table->string('audiotitle')->nullable();
        });
        Schema::create('release_subtitles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('releases_id');
            $table->unsignedInteger('subsid')->nullable();
            $table->string('subslanguage')->nullable();
        });
        Schema::create('release_audio_tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('releases_id');
            $table->string('album')->nullable();
            $table->string('performer')->nullable();
            $table->string('album_performer')->nullable();
            $table->string('track_name')->nullable();
            $table->unsignedInteger('track_position')->nullable();
            $table->unsignedInteger('track_position_total')->nullable();
            $table->string('genre')->nullable();
            $table->string('recorded_date')->nullable();
            $table->string('musicbrainz_album_id')->nullable();
            $table->string('musicbrainz_track_id')->nullable();
            $table->string('source_file')->nullable();
            $table->string('audio_format')->nullable();
        });
    }
}
