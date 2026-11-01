<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Enums\ImageAssetProfile;
use App\Models\MediaInfoProbe;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\VideoClipEncodeResult;
use App\Services\AdditionalProcessing\FreeDiskGuard;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\MediaTools;
use App\Services\AdditionalProcessing\VideoClipEncoder;
use App\Services\AdditionalProcessing\VideoFrameExtractor;
use App\Services\MediaInfo\Contracts\MediaInfoSnapshotWriter;
use App\Services\MediaInfo\DTO\MediaInfoProbeContext;
use App\Services\MediaInfo\Enums\MediaInfoSourceCompleteness;
use App\Services\MediaInfo\Enums\MediaInfoSourceKind;
use App\Services\ReleaseExtraService;
use App\Services\ReleaseImageService;
use App\Services\Releases\ClipGenerationPolicy;
use App\Support\Data\ImageProcessingResult;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mhor\MediaInfo\Container\MediaInfoContainer;
use Mhor\MediaInfo\MediaInfo;
use Mhor\MediaInfo\Type\Audio;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MediaExtractionServiceTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Application(sys_get_temp_dir());
        $container->instance('files', new Filesystem);
        Facade::setFacadeApplication($container);
        Log::swap(Mockery::mock()->shouldIgnoreMissing());
        Schema::swap(Mockery::mock()->shouldReceive('hasTable')->with('obfuscation_recovery_publications')->andReturn(false)->getMock());

        $this->tmpPath = sys_get_temp_dir().'/additional-media-'.uniqid('', true).'/';
        (new Filesystem)->makeDirectory($this->tmpPath, 0777, true, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tmpPath);
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function it_recognizes_jpeg_png_and_webp_samples_by_signature(): void
    {
        $config = $this->makeConfig();
        $fixtures = [
            'sample.jpg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00",
            'sample.png' => "\x89PNG\r\n\x1A\n\x00\x00\x00\rIHDR",
            'sample.webp' => "RIFF\x1A\x00\x00\x00WEBPVP8 ",
        ];
        $service = new MediaExtractionService(
            $config,
            Mockery::mock(ReleaseImageService::class),
            Mockery::mock(ReleaseExtraService::class),
            new VideoFrameExtractor($config),
        );

        foreach ($fixtures as $filename => $contents) {
            $path = $this->tmpPath.$filename;
            file_put_contents($path, $contents);

            $this->assertTrue($service->isValidImage($path), $filename.' should be recognized');
        }
    }

    #[Test]
    public function media_info_without_video_or_audio_tracks_is_not_reported_as_found(): void
    {
        $config = $this->makeConfig(['processMediaInfo' => true]);
        $file = $this->tmpPath.'truncated.mp4';
        file_put_contents($file, 'truncated media');
        $service = new MediaExtractionService(
            $config,
            Mockery::mock(ReleaseImageService::class),
            Mockery::mock(ReleaseExtraService::class)
                ->shouldNotReceive('addFromXml')
                ->getMock(),
            new VideoFrameExtractor($config),
        );
        $mediaInfo = Mockery::mock(MediaInfo::class);
        $mediaInfo->shouldReceive('getInfo')->once()->with($file, true)->andReturn(new MediaInfoContainer);
        $tools = new MediaTools;
        (new \ReflectionProperty(MediaTools::class, 'mediaInfo'))->setValue($tools, $mediaInfo);
        (new \ReflectionProperty(MediaExtractionService::class, 'mediaTools'))->setValue($service, $tools);

        $this->assertFalse($service->getMediaInfo($file, 42));
    }

    #[Test]
    public function additional_processing_writes_the_normalized_snapshot_with_supplied_provenance(): void
    {
        $config = $this->makeConfig(['processMediaInfo' => true]);
        $file = $this->tmpPath.'partial-head.mkv';
        file_put_contents($file, 'partial media');
        $container = new MediaInfoContainer;
        $container->add(new Audio);
        $context = new MediaInfoProbeContext(
            MediaInfoSourceKind::AdditionalProcessing,
            'source-name.mkv',
            MediaInfoSourceCompleteness::Partial,
        );
        $writer = Mockery::mock(MediaInfoSnapshotWriter::class);
        $writer->shouldReceive('capture')->once()->with(42, $container, $context)->andReturn(new MediaInfoProbe);
        $releaseExtra = Mockery::mock(ReleaseExtraService::class);
        $releaseExtra->shouldReceive('addFromXml')->once()->with(42, $container);
        $service = new MediaExtractionService(
            $config,
            Mockery::mock(ReleaseImageService::class),
            $releaseExtra,
            new VideoFrameExtractor($config),
            mediaInfoSnapshots: $writer,
        );
        $mediaInfo = Mockery::mock(MediaInfo::class);
        $mediaInfo->shouldReceive('getInfo')->once()->with($file, true)->andReturn($container);
        $tools = new MediaTools;
        (new \ReflectionProperty(MediaTools::class, 'mediaInfo'))->setValue($tools, $mediaInfo);
        (new \ReflectionProperty(MediaExtractionService::class, 'mediaTools'))->setValue($service, $tools);

        $this->assertFalse(
            $service->getMediaInfo($file, 42, $context),
            'The isolated unit container has no database for the legacy refinement that follows the snapshot write.',
        );
    }

    #[Test]
    public function get_sample_saves_a_preview_from_the_moovless_fallback_after_normal_extraction_fails(): void
    {
        $file = $this->tmpPath.'head.mp4';
        file_put_contents($file, 'mdat-first head');

        $calls = [];
        $frames = Mockery::mock(VideoFrameExtractor::class);
        $frames->shouldReceive('extractRepresentativeFrame')->once()->with($file, Mockery::type('string'))
            ->andReturnUsing(function (string $video, string $frame) use (&$calls): bool {
                $calls[] = 'representative';
                touch($frame);

                return false;
            });
        $frames->shouldReceive('extractMoovlessFrame')->once()->with($file, Mockery::type('string'))
            ->andReturnUsing(function (string $video, string $frame) use (&$calls): bool {
                $calls[] = 'moovless';
                touch($frame);

                return true;
            });

        $images = Mockery::mock(ReleaseImageService::class);
        $images->imgSavePath = $this->tmpPath.'preview/';
        $images->shouldReceive('saveExtractedImage')->once()
            ->with('moovless-guid', Mockery::type('string'), $images->imgSavePath, ImageAssetProfile::Preview)
            ->andReturn(ImageProcessingResult::success('preview.jpg', 64, 64, 'image/jpeg'));

        $log = Mockery::mock();
        $log->shouldReceive('debug')->once()->with('Moovless still stored', ['guid' => 'moovless-guid']);
        Log::swap($log);

        $service = new MediaExtractionService(
            $this->makeConfig(['processThumbnails' => true]),
            $images,
            Mockery::mock(ReleaseExtraService::class),
            $frames,
        );

        $this->assertTrue($service->getSample($file, $this->tmpPath, 'moovless-guid'));
        $this->assertSame(['representative', 'moovless'], $calls);
    }

    #[Test]
    public function get_sample_does_not_try_the_fallback_when_normal_extraction_succeeds(): void
    {
        $file = $this->tmpPath.'head.mp4';
        file_put_contents($file, 'playable file');

        $frames = Mockery::mock(VideoFrameExtractor::class);
        $frames->shouldReceive('extractRepresentativeFrame')->once()
            ->andReturnUsing(static fn (string $video, string $frame): bool => touch($frame));
        $frames->shouldNotReceive('extractMoovlessFrame');

        $images = Mockery::mock(ReleaseImageService::class);
        $images->imgSavePath = $this->tmpPath.'preview/';
        $images->shouldReceive('saveExtractedImage')->once()
            ->andReturn(ImageProcessingResult::success('preview.jpg', 64, 64, 'image/jpeg'));

        $log = Mockery::mock();
        $log->shouldNotReceive('debug');
        Log::swap($log);

        $service = new MediaExtractionService(
            $this->makeConfig(['processThumbnails' => true]),
            $images,
            Mockery::mock(ReleaseExtraService::class),
            $frames,
        );

        $this->assertTrue($service->getSample($file, $this->tmpPath, 'normal-guid'));
    }

    #[Test]
    public function get_sample_saves_nothing_when_the_fallback_also_fails(): void
    {
        $file = $this->tmpPath.'head.mp4';
        file_put_contents($file, 'mdat-first head');

        $frames = Mockery::mock(VideoFrameExtractor::class);
        $frames->shouldReceive('extractRepresentativeFrame')->once()
            ->andReturnUsing(static fn (string $video, string $frame): bool => ! touch($frame));
        $frames->shouldReceive('extractMoovlessFrame')->once()->andReturnFalse();

        $images = Mockery::mock(ReleaseImageService::class);
        $images->shouldNotReceive('saveExtractedImage');

        $service = new MediaExtractionService(
            $this->makeConfig(['processThumbnails' => true]),
            $images,
            Mockery::mock(ReleaseExtraService::class),
            $frames,
        );

        $this->assertFalse($service->getSample($file, $this->tmpPath, 'no-still-guid'));
    }

    #[Test]
    public function get_sample_does_not_log_a_moovless_still_it_failed_to_save(): void
    {
        $file = $this->tmpPath.'head.mp4';
        file_put_contents($file, 'mdat-first head');

        $frames = Mockery::mock(VideoFrameExtractor::class);
        $frames->shouldReceive('extractRepresentativeFrame')->once()->andReturnFalse();
        $frames->shouldReceive('extractMoovlessFrame')->once()
            ->andReturnUsing(static fn (string $video, string $frame): bool => touch($frame));

        $images = Mockery::mock(ReleaseImageService::class);
        $images->imgSavePath = $this->tmpPath.'preview/';
        $images->shouldReceive('saveExtractedImage')->once()
            ->andReturn(ImageProcessingResult::failure('Image basename is invalid.'));

        $log = Mockery::mock();
        $log->shouldNotReceive('debug');
        Log::swap($log);

        $service = new MediaExtractionService(
            $this->makeConfig(['processThumbnails' => true]),
            $images,
            Mockery::mock(ReleaseExtraService::class),
            $frames,
        );

        $this->assertFalse($service->getSample($file, $this->tmpPath, 'unsaved-guid'));
    }

    #[Test]
    public function checking_a_clip_for_a_usable_frame_never_reaches_the_moovless_fallback(): void
    {
        $source = $this->tmpPath.'source.mp4';
        file_put_contents($source, 'source');
        $clip = $this->tmpPath.'clip.mp4';
        file_put_contents($clip, 'clip');

        $frames = Mockery::mock(VideoFrameExtractor::class);
        $frames->shouldReceive('extractRepresentativeFrame')->once()->with($clip, Mockery::type('string'))
            ->andReturnUsing(static fn (string $video, string $frame): bool => ! touch($frame));
        $frames->shouldNotReceive('extractMoovlessFrame');

        $policy = Mockery::mock(ClipGenerationPolicy::class);
        $policy->shouldReceive('enabledForCategory')->andReturnTrue();
        $encoder = Mockery::mock(VideoClipEncoder::class);
        $encoder->shouldReceive('encode')->once()->andReturn(new VideoClipEncodeResult($clip, 'mp4', 'video/mp4', 30, 4));
        $disk = Mockery::mock(FreeDiskGuard::class);
        $disk->shouldReceive('allows')->andReturnTrue();
        $images = Mockery::mock(ReleaseImageService::class);
        $images->vidSavePath = $this->tmpPath.'video/';

        $service = new MediaExtractionService(
            $this->makeConfig(['processVideo' => true]),
            $images,
            Mockery::mock(ReleaseExtraService::class),
            $frames,
            clipPolicy: $policy,
            clipEncoder: $encoder,
            freeDiskGuard: $disk,
        );

        $this->assertFalse($service->getVideo($source, $this->tmpPath, 'clip-guid', 5040));
        $this->assertFileDoesNotExist($clip);
    }

    private function makeService(ProcessingConfiguration $config): MediaExtractionService
    {
        return new MediaExtractionService(
            $config,
            Mockery::mock(ReleaseImageService::class),
            Mockery::mock(ReleaseExtraService::class),
            new VideoFrameExtractor($config),
        );
    }
}
