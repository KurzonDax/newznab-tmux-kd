<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\VideoFrameExtractor;
use App\Services\Categorization\CategorizationService;
use App\Services\ReleaseExtraService;
use App\Services\ReleaseImageService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Mhor\MediaInfo\Container\MediaInfoContainer;
use Mhor\MediaInfo\MediaInfo;
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
            Mockery::mock(CategorizationService::class),
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
            Mockery::mock(CategorizationService::class),
            new VideoFrameExtractor($config),
        );
        $mediaInfo = Mockery::mock(MediaInfo::class);
        $mediaInfo->shouldReceive('getInfo')->once()->with($file, true)->andReturn(new MediaInfoContainer);
        $property = new \ReflectionProperty(MediaExtractionService::class, 'mediaInfo');
        $property->setValue($service, $mediaInfo);

        $this->assertFalse($service->getMediaInfo($file, 42));
    }
}
