<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\VideoFrameExtractor;
use App\Services\Categorization\CategorizationService;
use App\Services\ReleaseExtraService;
use App\Services\ReleaseImageService;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

class AdditionalProcessingAudioSampleGuardTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private string $tmpPath;

    private string $savePath;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('guid');
            $table->string('searchname')->default('');
            $table->string('fromname')->default('');
            $table->unsignedInteger('categories_id')->default(0);
            $table->unsignedInteger('groups_id')->default(0);
            $table->unsignedInteger('predb_id')->default(0);
            $table->integer('proc_pp')->default(0);
        });

        $this->tmpPath = $this->makeTempDirectory('nntmux-audio-guard-tmp').'/';
        $this->savePath = $this->makeTempDirectory('nntmux-audio-guard-store').'/';
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_zero_byte_ffmpeg_output_is_not_recorded_as_an_audio_sample(): void
    {
        [$service, $context] = $this->makeServiceForRelease(fn (string $target) => touch($target));

        $result = $service->getAudioInfo($this->tmpPath.'audiofile.MP3', 'MP3', $context, $this->tmpPath);

        $this->assertFalse($result['audioSample']);
        $this->assertFalse($context->foundAudioSample);
        $this->assertFileDoesNotExist($this->savePath.$context->release->guid.'.ogg');
        $this->assertFileDoesNotExist($this->tmpPath.$context->release->guid.'.ogg');
    }

    public function test_a_non_empty_ffmpeg_output_is_stored_as_an_audio_sample(): void
    {
        [$service, $context] = $this->makeServiceForRelease(
            fn (string $target) => file_put_contents($target, 'OggS-not-really-but-non-empty')
        );

        $result = $service->getAudioInfo($this->tmpPath.'audiofile.MP3', 'MP3', $context, $this->tmpPath);

        $this->assertTrue($result['audioSample']);
        $this->assertTrue($context->foundAudioSample);
        $this->assertFileExists($this->savePath.$context->release->guid.'.ogg');
    }

    /**
     * @return list<array{0: int}>
     */
    public static function ineligibleCategories(): array
    {
        return [
            [Category::GAME_NDS],
            [Category::MOVIE_FOREIGN],
            [Category::TV_HD],
            [Category::GAME_PS3],
            [Category::BOOKS_MAGAZINES],
        ];
    }

    #[DataProvider('ineligibleCategories')]
    public function test_releases_outside_the_music_and_unidentified_categories_are_skipped(int $categoryId): void
    {
        [$service, $context] = $this->makeServiceForRelease(
            fn (string $target) => file_put_contents($target, 'non-empty'),
            $categoryId
        );

        $result = $service->getAudioInfo($this->tmpPath.'audiofile.MP3', 'MP3', $context, $this->tmpPath);

        $this->assertFalse($result['audioSample']);
        $this->assertFalse($context->foundAudioSample);
        $this->assertFileDoesNotExist($this->savePath.$context->release->guid.'.ogg');
    }

    /**
     * @param  \Closure(string): mixed  $writeOutput  What the stubbed ffmpeg save() leaves on disk.
     * @return array{0: MediaExtractionService, 1: ReleaseProcessingContext}
     */
    private function makeServiceForRelease(\Closure $writeOutput, int $categoryId = Category::MUSIC_MP3): array
    {
        $releaseId = DB::table('releases')->insertGetId([
            'guid' => 'audio-guard-guid',
            'categories_id' => $categoryId,
        ]);
        $release = Release::query()->findOrFail($releaseId);

        $config = $this->makeConfig([
            'processAudioSample' => true,
            'processAudioInfo' => false,
            'audioSavePath' => $this->savePath,
        ]);

        $service = new MediaExtractionService(
            $config,
            Mockery::mock(ReleaseImageService::class),
            Mockery::mock(ReleaseExtraService::class),
            Mockery::mock(CategorizationService::class),
            new VideoFrameExtractor($config),
        );

        file_put_contents($this->tmpPath.'audiofile.MP3', 'pretend audio payload');

        $media = Mockery::mock();
        $media->shouldReceive('clip')->andReturnSelf();
        $media->shouldReceive('save')->andReturnUsing(function ($format, string $target) use ($writeOutput) {
            $writeOutput($target);
        });

        $ffmpeg = Mockery::mock(FFMpeg::class);
        $ffmpeg->shouldReceive('open')->andReturn($media);
        $ffprobe = Mockery::mock(FFProbe::class);
        $ffprobe->shouldReceive('isValid')->andReturnTrue();

        (new ReflectionProperty(MediaExtractionService::class, 'ffmpeg'))->setValue($service, $ffmpeg);
        (new ReflectionProperty(MediaExtractionService::class, 'ffprobe'))->setValue($service, $ffprobe);

        return [$service, new ReleaseProcessingContext($release)];
    }
}
