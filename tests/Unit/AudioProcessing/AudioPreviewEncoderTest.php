<?php

declare(strict_types=1);

namespace Tests\Unit\AudioProcessing;

use App\Services\AdditionalProcessing\MediaTools;
use App\Services\AudioProcessing\AudioPreviewEncoder;
use App\Services\AudioProcessing\AudioProcessingConfiguration;
use FFMpeg\Driver\FFMpegDriver;
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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Exercises the encoder's decisions -- which container, copy or transcode, and
 * how a short source is handled -- against a recording ffmpeg driver, so they
 * are covered on a machine with no ffmpeg installed. The clip's actual bytes are
 * ffmpeg's business and are checked separately where the binary exists.
 */
class AudioPreviewEncoderTest extends TestCase
{
    private string $tmpPath;

    private string $savePath;

    /** @var list<list<string>> */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Application(sys_get_temp_dir());
        $container->instance('files', new Filesystem);
        Facade::setFacadeApplication($container);
        Log::swap(Mockery::mock()->shouldIgnoreMissing());

        $this->tmpPath = sys_get_temp_dir().'/audio-encoder-'.bin2hex(random_bytes(6)).'/';
        $this->savePath = sys_get_temp_dir().'/audio-encoder-store-'.bin2hex(random_bytes(6)).'/';
        (new Filesystem)->makeDirectory($this->tmpPath, 0777, true, true);
        (new Filesystem)->makeDirectory($this->savePath, 0777, true, true);
        $this->commands = [];
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tmpPath);
        (new Filesystem)->deleteDirectory($this->savePath);
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    #[Test]
    public function a_browser_native_source_is_copied_into_its_own_container(): void
    {
        $encoder = $this->encoder('mp3');

        $result = $encoder->encode($this->sourceFile(), 'abc123', $this->tmpPath);

        $this->assertNotNull($result);
        $this->assertSame('mp3', $result->extension);
        $this->assertSame('audio/mpeg', $result->mimeType);
        $this->assertTrue($result->streamCopied);
        $this->assertGreaterThan(0, $result->bytes);
        $this->assertFileExists($this->savePath.'abc123.mp3');

        $command = $this->commands[0];
        $this->assertContainsSubsequence(['-c:a', 'copy'], $command);
        $this->assertContainsSubsequence(['-ss', '10'], $command);
        $this->assertContainsSubsequence(['-t', '30'], $command);
        $this->assertSame(30, $result->seconds);
        $this->assertContainsSubsequence(['-map_metadata', '-1'], $command);
        $this->assertContains('-vn', $command);
    }

    #[Test]
    public function aac_is_copied_into_an_mp4_container(): void
    {
        $result = $this->encoder('aac')->encode($this->sourceFile(), 'abc123', $this->tmpPath);

        $this->assertNotNull($result);
        $this->assertSame('m4a', $result->extension);
        $this->assertSame('audio/mp4', $result->mimeType);
        $this->assertTrue($result->streamCopied);
    }

    #[Test]
    public function a_source_no_browser_plays_is_transcoded_to_flac(): void
    {
        $result = $this->encoder('wavpack')->encode($this->sourceFile(), 'abc123', $this->tmpPath);

        $this->assertNotNull($result);
        $this->assertSame('flac', $result->extension);
        $this->assertSame('audio/flac', $result->mimeType);
        $this->assertFalse($result->streamCopied);
        $this->assertContainsSubsequence(['-c:a', 'flac', '-compression_level', '5'], $this->commands[0]);
    }

    #[Test]
    public function a_source_shorter_than_the_window_is_clipped_to_what_is_there(): void
    {
        $result = $this->encoder('mp3', sourceSeconds: 20.0)->encode($this->sourceFile(), 'abc123', $this->tmpPath);

        $this->assertNotNull($result);
        $this->assertSame(10, $result->seconds, 'Ten seconds of audio sit past the offset.');
        $this->assertCount(1, $this->commands);
        $this->assertContainsSubsequence(['-ss', '10'], $this->commands[0]);
        $this->assertContainsSubsequence(['-t', '10'], $this->commands[0]);
    }

    #[Test]
    public function a_source_shorter_than_the_offset_is_clipped_from_the_start(): void
    {
        $result = $this->encoder('mp3', sourceSeconds: 5.0)->encode($this->sourceFile(), 'abc123', $this->tmpPath);

        $this->assertNotNull($result);
        $this->assertSame(5, $result->seconds);
        $this->assertCount(1, $this->commands);
        $this->assertContainsSubsequence(['-ss', '0'], $this->commands[0]);
        $this->assertContainsSubsequence(['-t', '5'], $this->commands[0]);
    }

    #[Test]
    public function ffmpeg_producing_nothing_is_not_a_preview(): void
    {
        $encoder = $this->encoder('mp3', writesOutput: false);

        $this->assertNull($encoder->encode($this->sourceFile(), 'abc123', $this->tmpPath));
        $this->assertFileDoesNotExist($this->savePath.'abc123.mp3');
    }

    #[Test]
    public function the_spectrogram_covers_the_whole_fetched_span(): void
    {
        $encoder = $this->encoder('mp3');

        $this->assertTrue($encoder->renderSpectrogram($this->sourceFile(), 'abc123', $this->tmpPath));
        $this->assertFileExists($this->savePath.'abc123_spectrum.png');

        $command = $this->commands[0];
        $this->assertContains('showspectrumpic=s=1024x256:legend=1:color=intensity', $command);
        // No -ss/-t: the clip's window would hide where the low-pass sits.
        $this->assertNotContains('-ss', $command);
        $this->assertNotContains('-t', $command);
    }

    #[Test]
    public function the_spectrogram_is_skipped_when_the_setting_is_off(): void
    {
        $encoder = $this->encoder('mp3', spectrogram: false);

        $this->assertFalse($encoder->renderSpectrogram($this->sourceFile(), 'abc123', $this->tmpPath));
        $this->assertSame([], $this->commands);
    }

    private function sourceFile(): string
    {
        $path = $this->tmpPath.'audio.mp3';
        file_put_contents($path, str_repeat('a', 4096));

        return $path;
    }

    private function encoder(
        string $codec,
        bool $writesOutput = true,
        bool $spectrogram = true,
        float $sourceSeconds = 300.0,
    ): AudioPreviewEncoder {
        return new AudioPreviewEncoder(
            $this->config($spectrogram),
            $this->mediaTools($codec, $writesOutput, $sourceSeconds),
        );
    }

    private function config(bool $spectrogram): AudioProcessingConfiguration
    {
        $reflection = new ReflectionClass(AudioProcessingConfiguration::class);
        /** @var AudioProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'previewSeconds' => 30,
            'previewStartSeconds' => 10,
            'spectrogram' => $spectrogram,
            'savePath' => $this->savePath,
            'debugMode' => false,
        ] as $property => $value) {
            (new ReflectionProperty(AudioProcessingConfiguration::class, $property))->setValue($config, $value);
        }

        return $config;
    }

    private function mediaTools(string $codec, bool $writesOutput, float $sourceSeconds): MediaTools
    {
        $driver = Mockery::mock(FFMpegDriver::class);
        $driver->shouldReceive('command')->andReturnUsing(function (array $command) use ($writesOutput): string {
            $this->commands[] = array_values(array_map('strval', $command));

            if ($writesOutput) {
                file_put_contents((string) end($command), 'encoded-bytes');
            }

            return '';
        });

        $ffmpeg = Mockery::mock(FFMpeg::class);
        $ffmpeg->shouldReceive('getFFMpegDriver')->andReturn($driver);

        $ffprobe = Mockery::mock(FFProbe::class);
        $ffprobe->shouldReceive('streams')->andReturn(
            new StreamCollection([new Stream(['codec_type' => 'audio', 'codec_name' => $codec])])
        );
        $ffprobe->shouldReceive('format')->andReturn(new Format(['duration' => (string) $sourceSeconds]));

        $tools = new MediaTools;
        (new ReflectionProperty(MediaTools::class, 'ffmpeg'))->setValue($tools, $ffmpeg);
        (new ReflectionProperty(MediaTools::class, 'ffprobe'))->setValue($tools, $ffprobe);

        return $tools;
    }

    /**
     * @param  list<string>  $needle
     * @param  list<string>  $haystack
     */
    private function assertContainsSubsequence(array $needle, array $haystack): void
    {
        $found = false;
        $limit = count($haystack) - count($needle);
        for ($offset = 0; $offset <= $limit; $offset++) {
            if (array_slice($haystack, $offset, count($needle)) === $needle) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, implode(' ', $needle).' not found in: '.implode(' ', $haystack));
    }
}
