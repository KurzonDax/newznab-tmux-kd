<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ReleaseVideoClip;
use App\Services\AdditionalProcessing\ClipDiskGuard;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\VideoClipEncoder;
use App\Services\AdditionalProcessing\VideoFrameExtractor;
use App\Services\ReleaseExtraService;
use App\Services\ReleaseImageService;
use App\Services\Releases\ClipGenerationPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

/**
 * Backend branching for the Clip (see CONTEXT.md): stream-copy storage when
 * the toggle, codec safety, and disk headroom all allow it; the existing
 * transcode path in every other case; artifact deletion.
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

        $this->assertSame([], $service->transcodeCalls, 'The downscaled transcode must not be produced.');
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

    public function test_the_toggle_off_falls_back_to_the_transcode_without_probing(): void
    {
        $this->seedRelease('clip-guid');
        $encoderCommands = 0;
        $service = $this->makeService(
            clipEnabled: false,
            diskHasRoom: true,
            encoderRunner: function (array $command, int $timeout) use (&$encoderCommands): string {
                $encoderCommands++;

                return '';
            },
        );

        $service->getVideo($this->tmpPath.'source.mkv', $this->tmpPath, 'clip-guid', 6010);

        $this->assertSame(['clip-guid'], $service->transcodeCalls);
        $this->assertSame(0, $encoderCommands, 'With the toggle off no ffmpeg probe runs.');
        $this->assertSame(0, ReleaseVideoClip::query()->count());
    }

    public function test_a_non_browser_safe_source_falls_back_to_the_transcode(): void
    {
        $this->seedRelease('clip-guid');
        $service = $this->makeService(
            clipEnabled: true,
            diskHasRoom: true,
            encoderRunner: static fn (array $command, int $timeout): string => "Stream #0:0: Video: hevc (Main)\n  Stream #0:1: Audio: aac (LC)",
        );

        $service->getVideo($this->tmpPath.'source.mkv', $this->tmpPath, 'clip-guid', 6010);

        $this->assertSame(['clip-guid'], $service->transcodeCalls);
        $this->assertSame(0, ReleaseVideoClip::query()->count());
    }

    public function test_low_disk_falls_back_to_the_transcode_without_probing(): void
    {
        $this->seedRelease('clip-guid');
        $encoderCommands = 0;
        $service = $this->makeService(
            clipEnabled: true,
            diskHasRoom: false,
            encoderRunner: function (array $command, int $timeout) use (&$encoderCommands): string {
                $encoderCommands++;

                return '';
            },
        );

        $service->getVideo($this->tmpPath.'source.mkv', $this->tmpPath, 'clip-guid', 6010);

        $this->assertSame(['clip-guid'], $service->transcodeCalls);
        $this->assertSame(0, $encoderCommands);
    }

    public function test_a_null_category_falls_back_to_the_transcode(): void
    {
        $this->seedRelease('clip-guid');
        $service = $this->makeService(clipEnabled: true, diskHasRoom: true, encoderRunner: $this->safeH264Runner());

        $service->getVideo($this->tmpPath.'source.mkv', $this->tmpPath, 'clip-guid');

        $this->assertSame(['clip-guid'], $service->transcodeCalls);
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
     * @param  callable(list<string>, int): string  $encoderRunner
     */
    private function makeService(bool $clipEnabled, bool $diskHasRoom, callable $encoderRunner): RecordingMediaExtractionService
    {
        $config = $this->makeConfig([
            'processVideo' => true,
            'ffmpegPath' => '/usr/bin/ffmpeg',
        ]);

        return new RecordingMediaExtractionService(
            $config,
            new ReleaseImageService,
            Mockery::mock(ReleaseExtraService::class),
            new VideoFrameExtractor($config),
            clipPolicy: new StubClipGenerationPolicy($clipEnabled),
            clipEncoder: new VideoClipEncoder($encoderRunner),
            clipDiskGuard: new ClipDiskGuard(
                static fn (string $path): float => $diskHasRoom ? 500.0 : 50.0,
                static fn (string $path): float => 1000.0,
            ),
        );
    }

    /**
     * @return callable(list<string>, int): string
     */
    private function safeH264Runner(): callable
    {
        return static function (array $command, int $timeout): string {
            if (in_array('copy', $command, true)) {
                file_put_contents(end($command), 'remuxed clip bytes');

                return '';
            }

            if (str_contains((string) end($command), 'source.mkv')) {
                return "Stream #0:0(und): Video: h264 (High)\n  Stream #0:1(und): Audio: aac (LC)";
            }

            return 'Duration: 00:00:30.20, start: 0.000000, bitrate: 5000 kb/s';
        };
    }
}

final class RecordingMediaExtractionService extends MediaExtractionService
{
    /**
     * @var list<string>
     */
    public array $transcodeCalls = [];

    protected function storeTranscodedSample(string $fileLocation, string $tmpPath, string $guid): bool
    {
        $this->transcodeCalls[] = $guid;

        return false;
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
