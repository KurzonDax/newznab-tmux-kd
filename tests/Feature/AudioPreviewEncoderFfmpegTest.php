<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AdditionalProcessing\MediaTools;
use App\Services\AudioProcessing\AudioPreviewEncoder;
use App\Services\AudioProcessing\AudioProcessingConfiguration;
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
 * Stream-copy correctness is the point of the audio path, and only ffmpeg can
 * prove it, so this exercises the real binaries and skips itself where they are
 * absent. It sits in the Feature suite rather than tests/Integration so that it
 * runs wherever ffmpeg happens to be installed, instead of nowhere.
 *
 * Fixtures are generated on the fly rather than committed -- a few seconds of a
 * sine wave is all the encoder needs to have an opinion about.
 */
class AudioPreviewEncoderFfmpegTest extends TestCase
{
    private string $tmpPath;

    private string $savePath;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::binary('ffmpeg') === null || self::binary('ffprobe') === null) {
            $this->markTestSkipped('ffmpeg and ffprobe are required for this test.');
        }

        $container = new Application(sys_get_temp_dir());
        $container->instance('files', new Filesystem);
        Facade::setFacadeApplication($container);
        Log::swap(Mockery::mock()->shouldIgnoreMissing());

        $this->tmpPath = sys_get_temp_dir().'/audio-ffmpeg-'.bin2hex(random_bytes(6)).'/';
        $this->savePath = sys_get_temp_dir().'/audio-ffmpeg-store-'.bin2hex(random_bytes(6)).'/';
        (new Filesystem)->makeDirectory($this->tmpPath, 0777, true, true);
        (new Filesystem)->makeDirectory($this->savePath, 0777, true, true);
    }

    protected function tearDown(): void
    {
        // setUp() skips before these are set where ffmpeg is missing.
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
    public function an_mp3_preview_keeps_the_source_codec_and_carries_no_tags(): void
    {
        $source = $this->sine('mp3', 60, ['-b:a', '128k', '-metadata', 'album=A', '-metadata', 'artist=B']);

        $result = $this->encoder()->encode($source, 'abc123', $this->tmpPath);

        $this->assertNotNull($result);
        $this->assertSame('mp3', $result->extension);
        $this->assertTrue($result->streamCopied);
        $this->assertGreaterThan(0, $result->bytes);

        $clip = $this->savePath.'abc123.mp3';
        $this->assertSame('mp3', $this->probe($clip, 'stream=codec_name'));
        $this->assertSame('', $this->probe($clip, 'format_tags=album'));
        // A copy carries the source's bitrate; a re-encode would not.
        $this->assertEqualsWithDelta(128000, (int) $this->probe($clip, 'stream=bit_rate'), 4000);
    }

    #[Test]
    public function a_flac_source_yields_a_flac_stream_copy(): void
    {
        $result = $this->encoder()->encode($this->sine('flac', 60), 'abc123', $this->tmpPath);

        $this->assertNotNull($result);
        $this->assertSame('flac', $result->extension);
        $this->assertTrue($result->streamCopied);
        $this->assertSame('flac', $this->probe($this->savePath.'abc123.flac', 'stream=codec_name'));
    }

    #[Test]
    public function a_wavpack_source_is_transcoded_to_flac(): void
    {
        $result = $this->encoder()->encode($this->sine('wv', 60, ['-c:a', 'wavpack']), 'abc123', $this->tmpPath);

        $this->assertNotNull($result);
        $this->assertSame('flac', $result->extension);
        $this->assertFalse($result->streamCopied);
        $this->assertSame('flac', $this->probe($this->savePath.'abc123.flac', 'stream=codec_name'));
    }

    #[Test]
    public function a_source_shorter_than_the_window_is_clipped_to_what_exists(): void
    {
        $source = $this->sine('flac', 20);

        $result = $this->encoder()->encode($source, 'abc123', $this->tmpPath);

        $this->assertNotNull($result);
        // start=10, length=30, only 20s of audio: the last ten seconds of it.
        $this->assertSame(10, $result->seconds);
        // A stream copy carries the source's STREAMINFO, so the clip's declared
        // duration cannot be trusted -- but half the bytes is half the audio.
        $this->assertGreaterThan(0, $result->bytes);
        $this->assertLessThan((int) filesize($source) * 0.75, $result->bytes);
    }

    #[Test]
    public function a_source_shorter_than_the_offset_is_clipped_from_the_start(): void
    {
        $result = $this->encoder()->encode($this->sine('flac', 5), 'abc123', $this->tmpPath);

        $this->assertNotNull($result);
        $this->assertGreaterThan(0, $result->bytes);
        $this->assertSame(5, $result->seconds);
    }

    #[Test]
    public function the_spectrogram_is_a_real_png(): void
    {
        $source = $this->sine('flac', 20);

        $this->assertTrue($this->encoder()->renderSpectrogram($source, 'abc123', $this->tmpPath));

        $spectrogram = $this->savePath.'abc123_spectrum.png';
        $this->assertGreaterThan(0, filesize($spectrogram));
        $this->assertSame("\x89PNG", substr((string) file_get_contents($spectrogram), 0, 4));
    }

    /**
     * @param  list<string>  $extraArguments
     */
    private function sine(string $extension, int $seconds, array $extraArguments = []): string
    {
        $path = $this->tmpPath.'source.'.$extension;
        $command = array_merge(
            [self::binary('ffmpeg'), '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:duration='.$seconds],
            $extraArguments,
            [$path],
        );

        exec(implode(' ', array_map('escapeshellarg', array_filter($command))).' 2>/dev/null', $output, $status);
        $this->assertSame(0, $status, 'Could not build the '.$extension.' fixture.');

        return $path;
    }

    private function probe(string $path, string $entries): string
    {
        exec(implode(' ', array_map('escapeshellarg', [
            (string) self::binary('ffprobe'), '-v', 'error', '-show_entries', $entries,
            '-of', 'default=noprint_wrappers=1:nokey=1', $path,
        ])).' 2>/dev/null', $output);

        return trim(implode('', $output));
    }

    private function encoder(): AudioPreviewEncoder
    {
        $reflection = new ReflectionClass(AudioProcessingConfiguration::class);
        /** @var AudioProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'previewSeconds' => 30,
            'previewStartSeconds' => 10,
            'spectrogram' => true,
            'savePath' => $this->savePath,
            'debugMode' => false,
        ] as $property => $value) {
            (new ReflectionProperty(AudioProcessingConfiguration::class, $property))->setValue($config, $value);
        }

        return new AudioPreviewEncoder($config, new MediaTools);
    }

    private static function binary(string $name): ?string
    {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));

        return $path === '' ? null : $path;
    }
}
