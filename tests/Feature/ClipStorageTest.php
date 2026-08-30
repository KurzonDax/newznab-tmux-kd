<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ReleaseVideoClip;
use App\Services\AdditionalProcessing\FreeDiskGuard;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\VideoClipEncoder;
use App\Services\AdditionalProcessing\VideoFrameExtractor;
use App\Services\ReleaseExtraService;
use App\Services\ReleaseImageService;
use App\Services\Releases\ClipGenerationPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

/**
 * Backend branching for the Clip (see CONTEXT.md): stream-copy storage when
 * the toggle, codec safety, and disk headroom all allow it; no video artifact
 * at all in every other case (Clip-or-nothing); artifact deletion.
 */
class ClipStorageTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private string $coversRoot;

    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
            $table->integer('videostatus')->default(0);
        });

        $migration = require database_path('migrations/2026_08_27_150100_create_release_video_clips_table.php');
        $migration->up();

        $this->coversRoot = $this->makeTempDirectory('nntmux-covers');
        mkdir($this->coversRoot.'/video', 0775, true);
        config(['nntmux_settings.covers_path' => $this->coversRoot]);

        $this->tmpPath = $this->makeTempDirectory('nntmux-clip-tmp').'/';
        file_put_contents($this->tmpPath.'source.mkv', 'downloaded head window');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_browser_safe_source_stores_a_stream_copy_clip_and_its_metadata_row(): void
    {
        $releaseId = $this->seedRelease('clip-guid');
        $service = $this->makeService(
            clipEnabled: true,
            diskHasRoom: true,
            encoderRunner: $this->safeH264Runner(),
        );

        $this->assertTrue($service->getVideo($this->tmpPath.'source.mkv', $this->tmpPath, 'clip-guid', 6010));

        $this->assertFileExists($this->coversRoot.'/video/clip-guid.mp4');
        $this->assertSame('remuxed clip bytes', file_get_contents($this->coversRoot.'/video/clip-guid.mp4'));

        $clip = ReleaseVideoClip::query()->where('releases_id', $releaseId)->first();
        $this->assertNotNull($clip);
        $this->assertSame('mp4', $clip->extension);
        $this->assertSame('video/mp4', $clip->mime);
        $this->assertSame(30, $clip->duration_seconds);
        $this->assertSame(strlen('remuxed clip bytes'), $clip->bytes);

        $this->assertSame(1, (int) DB::table('releases')->where('id', $releaseId)->value('videostatus'));
    }

    public function test_a_browser_unsafe_source_stores_a_transcoded_clip_and_its_metadata_row(): void
    {
        $releaseId = $this->seedRelease('transcoded-clip-guid');
        $service = $this->makeService(
            clipEnabled: true,
            diskHasRoom: true,
            encoderRunner: static function (array $command, int $timeout): string {
                if (in_array('libx264', $command, true)) {
                    file_put_contents(end($command), 'transcoded clip bytes');

                    return '';
                }

                if (str_contains((string) end($command), 'source.mkv')) {
                    return "Stream #0:0: Video: mpeg4 (Advanced Simple Profile)\n  Stream #0:1: Audio: mp3, 48000 Hz";
                }

                return 'Duration: 00:00:24.00, start: 0.000000, bitrate: 2000 kb/s';
            },
            previewTargetSeconds: 24,
        );

        $this->assertTrue($service->getVideo($this->tmpPath.'source.mkv', $this->tmpPath, 'transcoded-clip-guid', 6010));

        $this->assertFileExists($this->coversRoot.'/video/transcoded-clip-guid.mp4');
        $clip = ReleaseVideoClip::query()->where('releases_id', $releaseId)->first();
        $this->assertNotNull($clip);
        $this->assertSame('mp4', $clip->extension);
        $this->assertSame('video/mp4', $clip->mime);
        $this->assertSame(24, $clip->duration_seconds);
        $this->assertSame(strlen('transcoded clip bytes'), $clip->bytes);
        $this->assertSame(1, (int) DB::table('releases')->where('id', $releaseId)->value('videostatus'));
    }

    public function test_the_toggle_off_stores_no_artifact_without_probing(): void
    {
        $releaseId = $this->seedRelease('clip-guid');
        $encoderCommands = 0;
        $service = $this->makeService(
            clipEnabled: false,
            diskHasRoom: true,
            encoderRunner: function (array $command, int $timeout) use (&$encoderCommands): string {
                $encoderCommands++;

                return '';
            },
        );

        $this->assertFalse($service->getVideo($this->tmpPath.'source.mkv', $this->tmpPath, 'clip-guid', 6010));

        $this->assertSame(0, $encoderCommands, 'With the toggle off no ffmpeg probe runs.');
        $this->assertNoVideoArtifacts($releaseId);
    }

    public function test_an_empty_fallback_transcode_stores_no_artifact(): void
    {
        $releaseId = $this->seedRelease('clip-guid');
        $service = $this->makeService(
            clipEnabled: true,
            diskHasRoom: true,
            encoderRunner: static fn (array $command, int $timeout): string => "Stream #0:0: Video: hevc (Main)\n  Stream #0:1: Audio: aac (LC)",
        );

        $this->assertFalse($service->getVideo($this->tmpPath.'source.mkv', $this->tmpPath, 'clip-guid', 6010));

        $this->assertNoVideoArtifacts($releaseId);
    }

    public function test_low_disk_stores_no_artifact_without_probing(): void
    {
        $releaseId = $this->seedRelease('clip-guid');
        $encoderCommands = 0;
        $service = $this->makeService(
            clipEnabled: true,
            diskHasRoom: false,
            encoderRunner: function (array $command, int $timeout) use (&$encoderCommands): string {
                $encoderCommands++;

                return '';
            },
        );

        $this->assertFalse($service->getVideo($this->tmpPath.'source.mkv', $this->tmpPath, 'clip-guid', 6010));

        $this->assertSame(0, $encoderCommands);
        $this->assertNoVideoArtifacts($releaseId);
    }

    public function test_a_null_category_stores_no_artifact(): void
    {
        $releaseId = $this->seedRelease('clip-guid');
        $service = $this->makeService(clipEnabled: true, diskHasRoom: true, encoderRunner: $this->safeH264Runner());

        $this->assertFalse($service->getVideo($this->tmpPath.'source.mkv', $this->tmpPath, 'clip-guid'));

        $this->assertNoVideoArtifacts($releaseId);
    }

    public function test_a_clip_below_the_duration_floor_is_discarded_entirely(): void
    {
        $releaseId = $this->seedRelease('clip-guid');
        $service = $this->makeService(
            clipEnabled: true,
            diskHasRoom: true,
            encoderRunner: $this->safeH264Runner(duration: '00:00:03.00'),
        );

        $this->assertFalse($service->getVideo($this->tmpPath.'source.mkv', $this->tmpPath, 'clip-guid', 6010));

        $this->assertNoVideoArtifacts($releaseId);
        $this->assertSame(
            [],
            glob($this->tmpPath.'clip_*') ?: [],
            'The floored encode is deleted, not left in the workspace.',
        );
    }

    public function test_a_floor_of_zero_stores_however_short_a_clip(): void
    {
        $releaseId = $this->seedRelease('clip-guid');
        $service = $this->makeService(
            clipEnabled: true,
            diskHasRoom: true,
            encoderRunner: $this->safeH264Runner(duration: '00:00:03.00'),
            clipMinimumSeconds: 0,
        );

        $this->assertTrue($service->getVideo($this->tmpPath.'source.mkv', $this->tmpPath, 'clip-guid', 6010));

        $this->assertFileExists($this->coversRoot.'/video/clip-guid.mp4');
        $this->assertSame(3, ReleaseVideoClip::query()->where('releases_id', $releaseId)->value('duration_seconds'));
    }

    public function test_an_unreadable_duration_is_not_floored(): void
    {
        $releaseId = $this->seedRelease('clip-guid');
        $service = $this->makeService(
            clipEnabled: true,
            diskHasRoom: true,
            encoderRunner: $this->safeH264Runner(duration: null),
        );

        $this->assertTrue($service->getVideo($this->tmpPath.'source.mkv', $this->tmpPath, 'clip-guid', 6010));

        $this->assertFileExists($this->coversRoot.'/video/clip-guid.mp4');
        $this->assertNull(ReleaseVideoClip::query()->where('releases_id', $releaseId)->value('duration_seconds'));
    }

    public function test_a_declined_clip_logs_no_error_trace_even_in_debug_mode(): void
    {
        $releaseId = $this->seedRelease('clip-guid');
        Log::spy();
        $service = $this->makeService(
            clipEnabled: true,
            diskHasRoom: true,
            encoderRunner: static fn (array $command, int $timeout): string => "Stream #0:0: Video: hevc (Main)\n  Stream #0:1: Audio: aac (LC)",
            debugMode: true,
        );

        $this->assertFalse($service->getVideo($this->tmpPath.'source.mkv', $this->tmpPath, 'clip-guid', 6010));

        Log::shouldNotHaveReceived('error');
        $this->assertNoVideoArtifacts($releaseId);
    }

    public function test_deleting_release_assets_removes_the_clip_file_and_the_cascade_removes_the_row(): void
    {
        $releaseId = $this->seedRelease('clip-guid');
        DB::table('release_video_clips')->insert([
            'releases_id' => $releaseId,
            'extension' => 'mp4',
            'mime' => 'video/mp4',
            'duration_seconds' => 30,
            'bytes' => 100,
        ]);
        file_put_contents($this->coversRoot.'/video/clip-guid.mp4', 'clip');
        file_put_contents($this->coversRoot.'/video/clip-guid.ogv', 'legacy');

        (new ReleaseImageService)->delete('clip-guid');

        $this->assertFileDoesNotExist($this->coversRoot.'/video/clip-guid.mp4');
        $this->assertFileDoesNotExist($this->coversRoot.'/video/clip-guid.ogv');

        DB::table('releases')->where('id', $releaseId)->delete();
        $this->assertSame(0, ReleaseVideoClip::query()->count(), 'The metadata row goes with the release row.');
    }

    private function seedRelease(string $guid): int
    {
        return (int) DB::table('releases')->insertGetId(['guid' => $guid, 'videostatus' => 0]);
    }

    /**
     * The declined case stores nothing: no file in the video store, no
     * metadata row, and videostatus untouched.
     */
    private function assertNoVideoArtifacts(int $releaseId): void
    {
        $this->assertSame([], glob($this->coversRoot.'/video/*') ?: []);
        $this->assertSame(0, ReleaseVideoClip::query()->count());
        $this->assertSame(0, (int) DB::table('releases')->where('id', $releaseId)->value('videostatus'));
    }

    /**
     * @param  callable(list<string>, int): string  $encoderRunner
     */
    private function makeService(
        bool $clipEnabled,
        bool $diskHasRoom,
        callable $encoderRunner,
        bool $debugMode = false,
        int $clipMinimumSeconds = 5,
        int $previewTargetSeconds = 30,
    ): MediaExtractionService {
        $config = $this->makeConfig([
            'processVideo' => true,
            'ffmpegPath' => '/usr/bin/ffmpeg',
            'debugMode' => $debugMode,
            'clipMinimumSeconds' => $clipMinimumSeconds,
            'previewTargetSeconds' => $previewTargetSeconds,
        ]);

        return new MediaExtractionService(
            $config,
            new ReleaseImageService,
            Mockery::mock(ReleaseExtraService::class),
            new VideoFrameExtractor($config),
            clipPolicy: new StubClipGenerationPolicy($clipEnabled),
            clipEncoder: new VideoClipEncoder($encoderRunner),
            freeDiskGuard: new FreeDiskGuard(
                static fn (string $path): float => $diskHasRoom ? 500.0 : 50.0,
                static fn (string $path): float => 1000.0,
            ),
        );
    }

    /**
     * @param  string|null  $duration  What the post-remux duration probe reports; null for an unreadable duration.
     * @return callable(list<string>, int): string
     */
    private function safeH264Runner(?string $duration = '00:00:30.20'): callable
    {
        return static function (array $command, int $timeout) use ($duration): string {
            if (in_array('copy', $command, true)) {
                file_put_contents(end($command), 'remuxed clip bytes');

                return '';
            }

            if (str_contains((string) end($command), 'source.mkv')) {
                return "Stream #0:0(und): Video: h264 (High)\n  Stream #0:1(und): Audio: aac (LC)";
            }

            return $duration === null
                ? 'Input #0, mov,mp4, from clip: no duration line'
                : 'Duration: '.$duration.', start: 0.000000, bitrate: 5000 kb/s';
        };
    }
}

/**
 * DB-free policy double: the real service resolves the leaf category's root
 * toggle from category tables these tests do not create.
 */
final class StubClipGenerationPolicy extends ClipGenerationPolicy
{
    public function __construct(private readonly bool $enabled) {}

    public function enabledForCategory(int $categoriesId): bool
    {
        return $this->enabled;
    }
}
