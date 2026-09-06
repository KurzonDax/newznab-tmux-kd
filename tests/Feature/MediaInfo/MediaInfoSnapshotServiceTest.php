<?php

declare(strict_types=1);

namespace Tests\Feature\MediaInfo;

use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AudioProcessing\AudioReleaseProcessor;
use App\Services\MediaInfo\Contracts\MediaInfoSnapshotWriter;
use App\Services\MediaInfo\DTO\MediaInfoProbeContext;
use App\Services\MediaInfo\Enums\MediaInfoSourceCompleteness;
use App\Services\MediaInfo\Enums\MediaInfoSourceKind;
use App\Services\MediaInfo\MediaInfoDiagnosticNormalizer;
use App\Services\MediaInfo\MediaInfoSnapshotService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mhor\MediaInfo\Attribute\Duration;
use Mhor\MediaInfo\Attribute\FloatRate;
use Mhor\MediaInfo\Attribute\Mode;
use Mhor\MediaInfo\Attribute\Rate;
use Mhor\MediaInfo\Attribute\Ratio;
use Mhor\MediaInfo\Container\MediaInfoContainer;
use Mhor\MediaInfo\Type\Audio;
use Mhor\MediaInfo\Type\General;
use Mhor\MediaInfo\Type\Subtitle;
use Mhor\MediaInfo\Type\Video;
use ReflectionProperty;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class MediaInfoSnapshotServiceTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '0'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::enableForeignKeyConstraints();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_it_captures_curated_container_music_and_every_reported_stream(): void
    {
        $releaseId = DB::table('releases')->insertGetId([]);
        $capturedAt = Carbon::parse('2026-09-06 14:30:00', 'UTC');
        $service = new MediaInfoSnapshotService;

        $probe = $service->capture(
            $releaseId,
            $this->representativeContainer(),
            new MediaInfoProbeContext(
                sourceKind: MediaInfoSourceKind::AdditionalProcessing,
                sourceFilename: 'The Last Observatory.mkv',
                sourceCompleteness: MediaInfoSourceCompleteness::Partial,
                capturedAt: $capturedAt,
            ),
        );

        self::assertSame(1, $probe->schema_version);
        $selected = $service->selectedForRelease($releaseId);
        self::assertNotNull($selected);
        self::assertSame([
            'embedded_title' => 'The Last Observatory',
            'source_filename' => 'The Last Observatory.mkv',
            'format' => 'Matroska',
            'duration_ms' => 6_480_000,
            'overall_bitrate_bps' => 18_600_000,
            'music_tags' => [
                'track_title' => 'After the Rain',
                'track_number' => 3,
                'track_total' => 9,
                'disc_number' => 1,
                'disc_total' => 1,
                'album' => 'Night Windows',
                'artist' => 'Northbound Quartet',
                'album_artist' => 'Northbound Quartet',
                'genre' => 'Jazz',
                'recorded_date' => '2024-10-18',
                'musicbrainz_release_id' => '9b76d14c-cc40-4d6f-9460-5a990de192e9',
                'musicbrainz_recording_id' => '019542bc-99c8-4ad2-851a-313bbc2f380d',
            ],
        ], $selected->container);
        self::assertSame(MediaInfoSourceKind::AdditionalProcessing->value, $selected->provenance['source_kind']);
        self::assertSame(MediaInfoSourceCompleteness::Partial->value, $selected->provenance['source_completeness']);
        self::assertSame('2026-09-06T14:30:00+00:00', $selected->provenance['captured_at']);

        self::assertCount(7, $selected->streams);
        self::assertSame(['video', 'video', 'audio', 'audio', 'subtitle', 'subtitle', 'subtitle'], array_column($selected->streams, 'type'));
        self::assertSame('11', $selected->streams[0]['id']);
        self::assertSame('11', $selected->streams[1]['id'], 'Repeated source IDs remain distinct tracks.');
        self::assertNull($selected->streams[3]['id'], 'A missing source ID is not fabricated.');
        self::assertSame(3840, $selected->streams[0]['width']);
        self::assertSame(2160, $selected->streams[0]['height']);
        self::assertSame(23.976, $selected->streams[0]['frame_rate']);
        self::assertSame(6, $selected->streams[2]['channels']);
        self::assertSame('5.1', $selected->streams[2]['channel_layout']);
        self::assertSame(48_000, $selected->streams[2]['sample_rate_hz']);
        self::assertTrue($selected->streams[4]['forced']);
        self::assertTrue($selected->streams[4]['default']);
        self::assertFalse($selected->streams[5]['forced']);
        self::assertFalse($selected->streams[5]['default']);
        self::assertNull($selected->streams[6]['forced']);
        self::assertNull($selected->streams[6]['default']);
    }

    public function test_production_processing_factories_inject_the_snapshot_writer(): void
    {
        $additionalWriter = (new ReflectionProperty(MediaExtractionService::class, 'mediaInfoSnapshots'))
            ->getValue($this->app->make(MediaExtractionService::class));
        $audioWriter = (new ReflectionProperty(AudioReleaseProcessor::class, 'mediaInfoSnapshots'))
            ->getValue($this->app->make(AudioReleaseProcessor::class));

        self::assertInstanceOf(MediaInfoSnapshotWriter::class, $additionalWriter);
        self::assertInstanceOf(MediaInfoSnapshotWriter::class, $audioWriter);
    }

    public function test_it_retains_two_snapshots_and_prefers_a_complete_predecessor_without_merging(): void
    {
        $releaseId = DB::table('releases')->insertGetId([]);
        $service = new MediaInfoSnapshotService;

        $service->capture($releaseId, $this->containerNamed('old complete'), $this->context(
            MediaInfoSourceCompleteness::Complete,
            '2026-09-06 10:00:00',
        ));
        $service->capture($releaseId, $this->containerNamed('newer partial'), $this->context(
            MediaInfoSourceCompleteness::Partial,
            '2026-09-06 11:00:00',
        ));

        $selected = $service->selectedForRelease($releaseId);
        self::assertNotNull($selected);
        self::assertSame('old complete', $selected->container['embedded_title']);
        self::assertSame([], $selected->streams, 'Tracks are never merged in from the newer probe.');

        $service->capture($releaseId, $this->containerNamed('newest unknown'), $this->context(
            MediaInfoSourceCompleteness::Unknown,
            '2026-09-06 12:00:00',
        ));

        self::assertSame(2, DB::table('media_info_probes')->where('releases_id', $releaseId)->count());
        self::assertSame(
            'newest unknown',
            $service->selectedForRelease($releaseId)?->container['embedded_title'],
            'Once the complete probe leaves the retained pair, the newest probe wins.',
        );
    }

    public function test_a_failed_capture_does_not_erase_existing_snapshots(): void
    {
        $releaseId = DB::table('releases')->insertGetId([]);
        $service = new MediaInfoSnapshotService;
        $service->capture($releaseId, $this->containerNamed('still here'), $this->context(
            MediaInfoSourceCompleteness::Complete,
            '2026-09-06 10:00:00',
        ));

        try {
            $service->capture($releaseId + 999, $this->containerNamed('invalid'), $this->context(
                MediaInfoSourceCompleteness::Partial,
                '2026-09-06 11:00:00',
            ));
            self::fail('Expected the foreign-key violation.');
        } catch (\Throwable) {
            self::assertSame('still here', $service->selectedForRelease($releaseId)?->container['embedded_title']);
            self::assertSame(1, DB::table('media_info_probes')->where('releases_id', $releaseId)->count());
        }
    }

    public function test_diagnostics_are_filtered_bounded_and_absent_from_consumer_and_model_serialization(): void
    {
        $releaseId = DB::table('releases')->insertGetId([]);
        $general = new General;
        $general->set('movie_name', '<b>Visible as text</b>');
        $general->set('cover_data', str_repeat('binary', 1_000));
        $general->set('complete_name', '/private/tmp/secret/movie.mkv');
        $general->set('oversized', str_repeat('x', MediaInfoDiagnosticNormalizer::MAX_VALUE_LENGTH + 50));
        $general->set('nested', ['a' => ['b' => ['c' => ['d' => 'too deep']]]]);
        $general->set('nested_sensitive', [
            'safe' => 'retained',
            'cover_data' => 'nested artwork',
            'deeper' => ['complete_name' => 'relative/private/movie.mkv'],
        ]);
        for ($index = 0; $index < MediaInfoDiagnosticNormalizer::MAX_KEYS + 10; $index++) {
            $general->set('extra_'.$index, $index);
        }
        $container = new MediaInfoContainer;
        $container->setGeneral($general);

        $probe = (new MediaInfoSnapshotService)->capture(
            $releaseId,
            $container,
            $this->context(MediaInfoSourceCompleteness::Unknown, '2026-09-06 10:00:00'),
        );

        self::assertTrue($probe->diagnostic_filtered);
        self::assertTrue($probe->diagnostic_truncated);
        self::assertArrayNotHasKey('diagnostic_raw', $probe->toArray());
        self::assertArrayNotHasKey('diagnostic_filtered', $probe->toArray());
        self::assertArrayNotHasKey('diagnostic_truncated', $probe->toArray());
        self::assertLessThanOrEqual(MediaInfoDiagnosticNormalizer::MAX_KEYS, count($probe->diagnostic_raw));
        self::assertArrayNotHasKey('cover_data', $probe->diagnostic_raw);
        self::assertArrayNotHasKey('complete_name', $probe->diagnostic_raw);
        self::assertSame('retained', $probe->diagnostic_raw['nested_sensitive']['safe']);
        self::assertArrayNotHasKey('cover_data', $probe->diagnostic_raw['nested_sensitive']);
        self::assertArrayNotHasKey('complete_name', $probe->diagnostic_raw['nested_sensitive']['deeper']);
        self::assertLessThanOrEqual(
            MediaInfoDiagnosticNormalizer::MAX_KEYS,
            $this->recursiveKeyCount($probe->diagnostic_raw),
        );

        $selected = (new MediaInfoSnapshotService)->selectedForRelease($releaseId);
        self::assertNotNull($selected);
        self::assertSame('<b>Visible as text</b>', $selected->container['embedded_title']);
        self::assertArrayNotHasKey('diagnostic_raw', $selected->container);
        self::assertArrayNotHasKey('diagnostic_raw', $selected->provenance);
    }

    public function test_curated_strings_are_bounded_without_losing_the_snapshot(): void
    {
        $releaseId = DB::table('releases')->insertGetId([]);
        $longValue = str_repeat('x', MediaInfoSnapshotService::MAX_CURATED_STRING_LENGTH + 100);
        $general = new General;
        $general->set('movie_name', $longValue);
        $general->set('album', $longValue);
        $video = new Video;
        $video->set('title', $longValue);
        $container = new MediaInfoContainer;
        $container->setGeneral($general);
        $container->add($video);

        $selected = (new MediaInfoSnapshotService)->capture(
            $releaseId,
            $container,
            new MediaInfoProbeContext(
                MediaInfoSourceKind::AdditionalProcessing,
                $longValue.'.mkv',
                MediaInfoSourceCompleteness::Complete,
            ),
        );

        self::assertSame(MediaInfoSnapshotService::MAX_CURATED_STRING_LENGTH, mb_strlen($selected->embedded_title));
        self::assertSame(MediaInfoSnapshotService::MAX_CURATED_STRING_LENGTH, mb_strlen($selected->source_filename));
        self::assertSame(
            MediaInfoSnapshotService::MAX_CURATED_STRING_LENGTH,
            mb_strlen((string) $selected->music_tags['album']),
        );
        self::assertSame(
            MediaInfoSnapshotService::MAX_CURATED_STRING_LENGTH,
            mb_strlen((string) $selected->tracks->first()?->title),
        );
    }

    public function test_sparse_and_historical_releases_remain_readable_and_release_deletion_cascades(): void
    {
        $releaseId = DB::table('releases')->insertGetId([]);
        $historicalReleaseId = DB::table('releases')->insertGetId([]);
        $service = new MediaInfoSnapshotService;

        self::assertNull($service->selectedForRelease($historicalReleaseId));

        $service->capture(
            $releaseId,
            new MediaInfoContainer,
            $this->context(MediaInfoSourceCompleteness::Unknown, '2026-09-06 10:00:00'),
        );
        $selected = $service->selectedForRelease($releaseId);
        self::assertNotNull($selected);
        self::assertSame([], $selected->streams);
        self::assertSame([
            'embedded_title' => null,
            'source_filename' => 'source.mkv',
            'format' => null,
            'duration_ms' => null,
            'overall_bitrate_bps' => null,
            'music_tags' => null,
        ], $selected->container);

        DB::table('releases')->where('id', $releaseId)->delete();
        self::assertSame(0, DB::table('media_info_probes')->where('releases_id', $releaseId)->count());
        self::assertSame(0, DB::table('media_info_tracks')->count());
    }

    private function containerNamed(string $title): MediaInfoContainer
    {
        $general = new General;
        $general->set('movie_name', $title);
        $container = new MediaInfoContainer;
        $container->setGeneral($general);

        return $container;
    }

    private function context(MediaInfoSourceCompleteness $completeness, string $capturedAt): MediaInfoProbeContext
    {
        return new MediaInfoProbeContext(
            MediaInfoSourceKind::AdditionalProcessing,
            'source.mkv',
            $completeness,
            Carbon::parse($capturedAt, 'UTC'),
        );
    }

    private function representativeContainer(): MediaInfoContainer
    {
        $general = new General;
        $general->set('movie_name', 'The Last Observatory');
        $general->set('format', new Mode('MKV', 'Matroska'));
        $general->set('duration', new Duration(6_480_000));
        $general->set('overall_bit_rate', new Mode('18600000', '18.6 Mb/s'));
        $general->set('track_name', 'After the Rain');
        $general->set('track_name_position', '3');
        $general->set('track_name_total', '9');
        $general->set('part_position', '1');
        $general->set('part_position_total', '1');
        $general->set('album', 'Night Windows');
        $general->set('performer', 'Northbound Quartet');
        $general->set('album_performer', 'Northbound Quartet');
        $general->set('genre', 'Jazz');
        $general->set('recorded_date', '2024-10-18');
        $general->set('musicbrainz_releaseid', '9b76d14c-cc40-4d6f-9460-5a990de192e9');
        $general->set('musicbrainz_recordingid', '019542bc-99c8-4ad2-851a-313bbc2f380d');

        $container = new MediaInfoContainer;
        $container->setGeneral($general);
        $container->add($this->video('11', 'Main feature', 3840, 2160));
        $container->add($this->video('11', 'Alternate angle', 1920, 1080));
        $container->add($this->audio('2', 'English surround', 'English'));
        $container->add($this->audio(null, 'Director commentary', null));
        $container->add($this->subtitle('4', 'Foreign dialogue only', 'English', 'Yes', 'Yes'));
        $container->add($this->subtitle('5', 'English SDH', 'English', 'No', 'No'));
        $container->add($this->subtitle(null, null, null, null, null));

        return $container;
    }

    private function video(string $id, string $title, int $width, int $height): Video
    {
        $video = new Video;
        $video->set('id', new Mode($id, $id));
        $video->set('streamorder', '1');
        $video->set('title', $title);
        $video->set('language', ['en', 'English']);
        $video->set('format', new Mode('HEVC', 'HEVC'));
        $video->set('codec_id', 'V_MPEGH/ISO/HEVC');
        $video->set('width', new Rate((string) $width, number_format($width).' pixels'));
        $video->set('height', new Rate((string) $height, number_format($height).' pixels'));
        $video->set('display_aspect_ratio', new Ratio(1.778, '16:9'));
        $video->set('frame_rate', new FloatRate('23.976', '23.976 FPS'));
        $video->set('bit_rate', new Rate('16200000', '16.2 Mb/s'));
        $video->set('format_profile', 'Main 10@L5.1');
        $video->set('bit_depth', new Rate('10', '10 bits'));
        $video->set('hdr_format', 'HDR10');
        $video->set('colour_primaries', 'BT.2020');
        $video->set('transfer_characteristics', 'PQ');
        $video->set('matrix_coefficients', 'BT.2020 non-constant');

        return $video;
    }

    private function audio(?string $id, string $title, ?string $language): Audio
    {
        $audio = new Audio;
        if ($id !== null) {
            $audio->set('id', new Mode($id, $id));
        }
        $audio->set('title', $title);
        if ($language !== null) {
            $audio->set('language', ['en', $language]);
        }
        $audio->set('format', new Mode('E-AC-3', 'E-AC-3'));
        $audio->set('codec_id', 'A_EAC3');
        $audio->set('channel_s', new Rate('6', '6 channels'));
        $audio->set('channel_layout', 'L R C LFE Ls Rs');
        $audio->set('channel_positions', new Mode('5.1', '5.1'));
        $audio->set('sampling_rate', new Rate('48000', '48.0 kHz'));
        $audio->set('bit_rate', new Rate('640000', '640 kb/s'));
        $audio->set('bit_depth', new Rate('24', '24 bits'));
        $audio->set('duration', new Duration(6_480_000));

        return $audio;
    }

    private function subtitle(?string $id, ?string $title, ?string $language, ?string $forced, ?string $default): Subtitle
    {
        $subtitle = new Subtitle;
        if ($id !== null) {
            $subtitle->set('id', new Mode($id, $id));
        }
        if ($title !== null) {
            $subtitle->set('title', $title);
        }
        if ($language !== null) {
            $subtitle->set('language', ['en', $language]);
        }
        $subtitle->set('format', new Mode('UTF-8', 'UTF-8'));
        if ($forced !== null) {
            $subtitle->set('forced', new Mode($forced, $forced));
        }
        if ($default !== null) {
            $subtitle->set('default', new Mode($default, $default));
        }

        return $subtitle;
    }

    private function createSchema(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('media_info_probes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('releases_id')->constrained('releases')->cascadeOnDelete();
            $table->dateTime('captured_at');
            $table->string('source_kind', 32);
            $table->string('source_filename')->nullable();
            $table->string('source_completeness', 16);
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
            $table->foreignId('media_info_probe_id')->constrained('media_info_probes')->cascadeOnDelete();
            $table->string('type', 16);
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
            $table->unique(['media_info_probe_id', 'type', 'track_index']);
        });
    }

    /** @param array<array-key, mixed> $values */
    private function recursiveKeyCount(array $values): int
    {
        $count = count($values);
        foreach ($values as $value) {
            if (is_array($value)) {
                $count += $this->recursiveKeyCount($value);
            }
        }

        return $count;
    }
}
