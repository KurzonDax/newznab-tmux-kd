<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AdditionalProcessing\MediaTools;
use App\Services\AudioProcessing\AudioDecodableLengthProbe;
use App\Services\AudioProcessing\AudioPreviewEncoder;
use App\Services\AudioProcessing\AudioProcessingConfiguration;
use App\Services\AudioProcessing\WavPackDecoder;
use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\Driver\FFProbeDriver;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\FFProbe\DataMapping\Format;
use FFMpeg\FFProbe\DataMapping\Stream;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

class AudioWavPackFallbackTest extends TestCase
{
    private string $tmpPath;

    private string $savePath;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::binary('ffmpeg') === null || self::binary('ffprobe') === null || self::binary('wvunpack') === null) {
            $this->markTestSkipped('ffmpeg, ffprobe, and wvunpack are required for this test.');
        }

        $container = new Application(sys_get_temp_dir());
        $container->instance('files', new Filesystem);
        Facade::setFacadeApplication($container);
        Log::swap(Mockery::mock()->shouldIgnoreMissing());

        $this->tmpPath = sys_get_temp_dir().'/audio-wavpack-'.bin2hex(random_bytes(6)).'/';
        $this->savePath = sys_get_temp_dir().'/audio-wavpack-store-'.bin2hex(random_bytes(6)).'/';
        (new Filesystem)->makeDirectory($this->tmpPath, 0777, true, true);
        (new Filesystem)->makeDirectory($this->savePath, 0777, true, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpPath, $this->savePath)) {
            (new Filesystem)->deleteDirectory($this->tmpPath);
            (new Filesystem)->deleteDirectory($this->savePath);
        }

        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    #[Test]
    public function the_probe_uses_wvunpack_when_ffprobe_cannot_demux_float_wavpack(): void
    {
        $source = $this->copyFixture();
        $driver = Mockery::mock(FFProbeDriver::class);
        $driver->shouldReceive('command')->andReturnUsing(function (array $command): string {
            $path = (string) end($command);
            if (str_ends_with($path, '.wv')) {
                throw new RuntimeException('Forced ffmpeg-only demux failure.');
            }

            return $this->packetTimestamps($path);
        });

        $tools = $this->mediaTools($driver);
        $probe = new AudioDecodableLengthProbe($tools, new WavPackDecoder($tools));

        $this->assertEqualsWithDelta(2.0, $probe->demuxedSeconds($source), 0.05);
    }

    #[Test]
    public function the_probe_decodes_the_available_span_of_a_truncated_float_wavpack_file(): void
    {
        $source = $this->copyFixture();
        $contents = (string) file_get_contents($source);
        file_put_contents($source, substr($contents, 0, (int) floor(strlen($contents) * 0.75)));

        $driver = Mockery::mock(FFProbeDriver::class);
        $driver->shouldReceive('command')->andReturnUsing(function (array $command): string {
            $path = (string) end($command);
            if (str_ends_with($path, '.wv')) {
                throw new RuntimeException('Forced ffmpeg-only demux failure.');
            }

            return $this->packetTimestamps($path);
        });

        $tools = $this->mediaTools($driver);
        $probe = new AudioDecodableLengthProbe($tools, new WavPackDecoder($tools));
        $seconds = $probe->demuxedSeconds($source);

        $this->assertGreaterThan(1.0, $seconds);
        $this->assertLessThan(2.0, $seconds);
    }

    #[Test]
    #[DataProvider('wavPackSources')]
    public function the_encoder_uses_wvunpack_when_ffmpeg_cannot_decode_float_wavpack(bool $truncate): void
    {
        $source = $this->copyFixture();
        if ($truncate) {
            $contents = (string) file_get_contents($source);
            file_put_contents($source, substr($contents, 0, (int) floor(strlen($contents) * 0.75)));
        }
        $ffmpegDriver = Mockery::mock(FFMpegDriver::class);
        $ffmpegDriver->shouldReceive('command')->andReturnUsing(function (array $command): string {
            $inputIndex = array_search('-i', $command, true);
            $inputPath = is_int($inputIndex) ? (string) ($command[$inputIndex + 1] ?? '') : '';
            if (str_ends_with($inputPath, '.wv')) {
                throw new RuntimeException('Forced ffmpeg-only decode failure.');
            }

            $this->runFfmpeg($command);

            return '';
        });

        $ffmpeg = Mockery::mock(FFMpeg::class);
        $ffmpeg->shouldReceive('getFFMpegDriver')->andReturn($ffmpegDriver);
        $ffprobe = Mockery::mock(FFProbe::class);
        $ffprobe->shouldReceive('streams')->andReturn(
            new StreamCollection([new Stream(['codec_type' => 'audio', 'codec_name' => 'wavpack'])])
        );
        $ffprobe->shouldReceive('format')->andReturn(new Format(['duration' => '2.0']));

        $tools = new MediaTools;
        (new ReflectionProperty(MediaTools::class, 'ffmpeg'))->setValue($tools, $ffmpeg);
        (new ReflectionProperty(MediaTools::class, 'ffprobe'))->setValue($tools, $ffprobe);

        $encoder = new AudioPreviewEncoder(
            $this->configuration(),
            $tools,
            new WavPackDecoder($tools),
        );
        $result = $encoder->encode($source, 'float32', $this->tmpPath);

        $this->assertNotNull($result);
        $this->assertSame('flac', $result->extension);
        $this->assertGreaterThan(0, $result->bytes);
        $this->assertFileExists($this->savePath.'float32.flac');
        $this->assertTrue($encoder->renderSpectrogram($source, 'float32', $this->tmpPath));
        $this->assertFileExists($this->savePath.'float32_spectrum.png');
        $this->assertFileDoesNotExist($this->tmpPath.'float32_wavpack.wav');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function wavPackSources(): iterable
    {
        yield 'complete source' => [false];
        yield 'truncated source' => [true];
    }

    private function copyFixture(): string
    {
        $source = $this->tmpPath.'float32-tone.wv';
        copy(dirname(__DIR__).'/Fixtures/Audio/float32-tone.wv', $source);

        return $source;
    }

    private function packetTimestamps(string $path): string
    {
        $command = [
            (string) self::binary('ffprobe'),
            '-v', 'error',
            '-select_streams', 'a:0',
            '-show_entries', 'packet=pts_time',
            '-of', 'csv=p=0',
            $path,
        ];
        exec(implode(' ', array_map('escapeshellarg', $command)).' 2>/dev/null', $output, $status);
        $this->assertSame(0, $status, 'ffprobe could not inspect the decoded WAV fallback.');

        return implode("\n", $output);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runFfmpeg(array $arguments): void
    {
        $command = [(string) self::binary('ffmpeg'), ...$arguments];
        exec(implode(' ', array_map('escapeshellarg', $command)).' 2>/dev/null', $output, $status);
        $this->assertSame(0, $status, 'ffmpeg could not process the decoded WAV fallback.');
    }

    private function configuration(): AudioProcessingConfiguration
    {
        $reflection = new ReflectionClass(AudioProcessingConfiguration::class);
        /** @var AudioProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'previewSeconds' => 2,
            'previewStartSeconds' => 0,
            'spectrogram' => true,
            'savePath' => $this->savePath,
            'debugMode' => false,
        ] as $property => $value) {
            (new ReflectionProperty(AudioProcessingConfiguration::class, $property))->setValue($config, $value);
        }

        return $config;
    }

    private function mediaTools(FFProbeDriver $driver): MediaTools
    {
        $ffprobe = Mockery::mock(FFProbe::class);
        $ffprobe->shouldReceive('getFFProbeDriver')->andReturn($driver);

        $tools = new MediaTools;
        (new ReflectionProperty(MediaTools::class, 'ffprobe'))->setValue($tools, $ffprobe);

        return $tools;
    }

    private static function binary(string $name): ?string
    {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));

        return $path === '' ? null : $path;
    }
}
