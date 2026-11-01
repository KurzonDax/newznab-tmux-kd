<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AdditionalProcessing\MoovlessH264Stream;
use App\Services\AdditionalProcessing\VideoFrameExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

/**
 * Only the real decoder can prove the rebuilt settings are right, so this runs
 * ffmpeg on real encodes cut at their moov and skips itself where ffmpeg, ffprobe
 * or libx264 is absent.
 */
class MoovlessStillFfmpegTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private string $tmpPath;

    /** @var list<list<string>> */
    private array $commands = [];

    private ?string $lastFrameStream = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::binary('ffmpeg') === null || self::binary('ffprobe') === null) {
            $this->markTestSkipped('ffmpeg and ffprobe are required for this test.');
        }
        if (! str_contains((string) shell_exec('ffmpeg -hide_banner -encoders 2>/dev/null'), 'libx264')) {
            $this->markTestSkipped('libx264 is required for this test.');
        }

        $this->tmpPath = $this->makeTempDirectory('moovless-still').'/';
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function sourceSizes(): array
    {
        return [
            '1280x720' => [1280, 720],
            '1920x1080' => [1920, 1080],
            'portrait 1080x1920' => [1080, 1920],
        ];
    }

    #[Test]
    #[DataProvider('sourceSizes')]
    public function a_cut_file_only_the_moovless_route_can_read_gives_a_jpeg_of_the_source_size(int $width, int $height): void
    {
        $complete = $this->encode($width, $height, ['-crf', '23']);
        $cut = $this->cutAtMoov($complete);
        $frame = $this->tmpPath.'frame.jpg';
        $extractor = $this->extractor();

        $this->assertFalse($extractor->extractRepresentativeFrame($cut, $frame));
        $this->assertTrue($extractor->extractMoovlessFrame($cut, $frame));

        $size = getimagesize($frame);
        $this->assertNotFalse($size);
        $this->assertSame([$width, $height, IMAGETYPE_JPEG], [$size[0], $size[1], $size[2]]);
        $this->assertFileDoesNotExist($frame.'.h264');
    }

    /**
     * @return array<string, array{list<string>, bool, string}>
     */
    public static function encodes(): array
    {
        return [
            'crf 18' => [['-crf', '18'], false, 'rc=crf'],
            'bitrate 1M' => [['-b:v', '1M'], false, 'rc=abr'],
            'constant qp 20' => [['-qp', '20'], false, 'rc=cqp'],
            'no b-frames' => [['-bf', '0'], false, 'bframes=0'],
            'strict b-pyramid' => [['-x264-params', 'b-pyramid=strict'], false, 'b_pyramid=1'],
            'two-pass' => [['-b:v', '1M'], true, 'rc=2pass'],
        ];
    }

    /**
     * @param  list<string>  $arguments
     */
    #[Test]
    #[DataProvider('encodes')]
    public function keyframes_from_the_rebuilt_stream_are_byte_identical_to_the_complete_file(array $arguments, bool $twoPass, string $optionInText): void
    {
        $complete = $this->encode(1280, 720, $arguments, $twoPass);
        $cut = $this->cutAtMoov($complete);
        $this->assertStringContainsString($optionInText, (string) file_get_contents($cut, false, null, 0, 4096));

        $this->assertTrue($this->extractor()->extractMoovlessFrame($cut, $this->tmpPath.'frame.jpg'));

        $stream = MoovlessH264Stream::fromHead((string) file_get_contents($cut));
        $this->assertNotNull($stream);
        $this->assertTrue($stream->usesEncoderText());
        $groups = $stream->keyframeGroups();
        // The accepted run wrote the rebuilt SPS and PPS first, whichever group it took.
        [, $sps, $pps] = explode("\x00\x00\x00\x01", (string) $this->lastFrameStream, 4);
        $settings = "\x00\x00\x00\x01".$sps."\x00\x00\x00\x01".$pps;

        // The walker can take audio bytes for a keyframe. That group does not decode,
        // and its false length can hide real keyframes. Which ones depends on the AAC
        // bytes, which differ by CPU, so what must hold is that everything decoded is
        // a real keyframe, in order.
        $decoded = [];
        foreach ($groups as $group) {
            $sha = $this->decodeToGraySha($settings.$group, 1280, 720);
            if ($sha !== null) {
                $decoded[] = $sha;
            }
        }

        $keyframes = $this->keyframesOfCompleteFile($complete, 1280, 720);
        $this->assertSame(array_values(array_intersect($keyframes, $decoded)), $decoded);
        $this->assertNotSame([], $decoded);
    }

    #[Test]
    public function repeated_headers_take_the_in_band_route_with_no_width_search(): void
    {
        $cut = $this->cutAtMoov($this->encode(1280, 720, ['-x264-params', 'repeat-headers=1']));

        $this->assertTrue($this->extractor()->extractMoovlessFrame($cut, $this->tmpPath.'frame.jpg'));

        $stream = MoovlessH264Stream::fromHead((string) file_get_contents($cut));
        $this->assertNotNull($stream);
        $this->assertFalse($stream->usesEncoderText());
        $this->assertSame([], array_values(array_filter(
            $this->commands,
            static fn (array $command): bool => in_array('null', $command, true),
        )));
    }

    /**
     * @return array<string, array{list<string>, bool}>
     */
    public static function refusedFiles(): array
    {
        return [
            'custom quant matrix' => [['-c:v', 'libx264', '-x264-params', 'cqm=jvt'], true],
            'not H.264' => [['-c:v', 'mpeg4'], true],
            'moov first' => [['-c:v', 'libx264', '-movflags', '+faststart'], false],
        ];
    }

    /**
     * @param  list<string>  $arguments
     */
    #[Test]
    #[DataProvider('refusedFiles')]
    public function a_file_with_no_route_returns_false_and_leaves_no_temporary_stream_file(array $arguments, bool $cutAtMoov): void
    {
        $file = $this->encode(1280, 720, $arguments);
        if ($cutAtMoov) {
            $file = $this->cutAtMoov($file);
        }
        $frame = $this->tmpPath.'frame.jpg';

        $this->assertFalse($this->extractor()->extractMoovlessFrame($file, $frame));
        $this->assertFileDoesNotExist($frame.'.h264');
    }

    private function extractor(): VideoFrameExtractor
    {
        return new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => (string) self::binary('ffmpeg'), 'timeoutSeconds' => 60]),
            function (array $command, int $timeout): string {
                $this->commands[] = $command;
                $input = $command[array_search('-i', $command, true) + 1];
                if (! in_array('null', $command, true) && str_ends_with($input, '.h264')) {
                    $this->lastFrameStream = (string) file_get_contents($input);
                }

                $process = new Process($command);
                $process->setTimeout($timeout);
                $process->run();

                return $process->getOutput().$process->getErrorOutput();
            },
        );
    }

    /**
     * @param  list<string>  $arguments
     */
    private function encode(int $width, int $height, array $arguments, bool $twoPass = false): string
    {
        $path = $this->tmpPath.'source-'.uniqid().'.mp4';
        $video = ['-f', 'lavfi', '-i', 'testsrc2=size='.$width.'x'.$height.':rate=25:duration=3'];
        $audio = ['-f', 'lavfi', '-i', 'sine=frequency=440:duration=3'];
        $codec = in_array('-c:v', $arguments, true) ? [] : ['-c:v', 'libx264'];
        $picture = [...$codec, '-pix_fmt', 'yuv420p', '-g', '25', ...$arguments];

        if ($twoPass) {
            $log = $this->tmpPath.'passlog-'.uniqid();
            $this->ffmpeg([...$video, ...$picture, '-pass', '1', '-passlogfile', $log, '-an', '-f', 'null', '/dev/null']);
            $this->ffmpeg([...$video, ...$audio, ...$picture, '-pass', '2', '-passlogfile', $log, '-c:a', 'aac', $path]);

            return $path;
        }

        $this->ffmpeg([...$video, ...$audio, ...$picture, '-c:a', 'aac', $path]);

        return $path;
    }

    private function cutAtMoov(string $path): string
    {
        $file = (string) file_get_contents($path);
        $offset = 0;
        while ($offset + 8 <= strlen($file)) {
            $size = (int) (unpack('N', substr($file, $offset, 4))[1] ?? 0);
            if (substr($file, $offset + 4, 4) === 'moov') {
                $cut = $path.'.cut.mp4';
                file_put_contents($cut, substr($file, 0, $offset));

                return $cut;
            }
            $this->assertGreaterThanOrEqual(8, $size, 'The fixture has an atom the walk cannot follow.');
            $offset += $size;
        }

        $this->fail('The fixture has no moov to cut at.');
    }

    /**
     * @return list<string>
     */
    private function keyframesOfCompleteFile(string $path, int $width, int $height): array
    {
        $raw = $path.'.gray';
        $this->ffmpeg(['-skip_frame', 'nokey', '-i', $path, '-map', '0:v:0', '-fps_mode', 'passthrough', '-f', 'rawvideo', '-pix_fmt', 'gray', $raw]);

        return array_map(sha1(...), str_split((string) file_get_contents($raw), $width * $height));
    }

    /**
     * The SHA-1 of the gray frame, or null when the group has a decoder error.
     */
    private function decodeToGraySha(string $stream, int $width, int $height): ?string
    {
        $input = $this->tmpPath.'group-'.uniqid().'.h264';
        $raw = $input.'.gray';
        file_put_contents($input, $stream);

        $process = new Process([
            (string) self::binary('ffmpeg'), '-y', '-hide_banner', '-nostdin', '-v', 'info', '-xerror',
            '-f', 'h264', '-i', $input, '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'gray', $raw,
        ]);
        $process->run();
        if (! $process->isSuccessful() || str_contains($process->getErrorOutput(), 'concealing')) {
            return null;
        }
        $this->assertSame($width * $height, filesize($raw));

        return sha1((string) file_get_contents($raw));
    }

    /**
     * @param  list<string>  $arguments
     */
    private function ffmpeg(array $arguments): void
    {
        $command = [(string) self::binary('ffmpeg'), '-y', '-hide_banner', '-loglevel', 'error', '-nostdin', ...$arguments];
        exec(implode(' ', array_map('escapeshellarg', $command)).' 2>&1', $output, $status);
        $this->assertSame(0, $status, 'ffmpeg failed: '.implode("\n", $output));
    }

    private static function binary(string $name): ?string
    {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));

        return $path === '' ? null : $path;
    }
}
