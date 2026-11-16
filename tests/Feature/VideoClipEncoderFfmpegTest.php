<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AdditionalProcessing\VideoClipEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Only ffmpeg can prove the clip scale filter parses and sizes the picture as
 * intended, so this runs the real binaries and skips itself where they are
 * absent. Hardware acceleration is off, so every case is a software encode.
 */
class VideoClipEncoderFfmpegTest extends TestCase
{
    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::binary('ffmpeg') === null || self::binary('ffprobe') === null) {
            $this->markTestSkipped('ffmpeg and ffprobe are required for this test.');
        }

        $this->tmpPath = $this->makeTempDirectory('clip-ffmpeg').'/';
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sourceSizes(): array
    {
        return [
            'larger landscape is fitted to 1280 wide' => ['2560x1080', '1280x540'],
            'portrait is fitted to 720 high' => ['1080x2400', '324x720'],
            'smaller than 720p is not upscaled' => ['640x360', '640x360'],
        ];
    }

    #[Test]
    #[DataProvider('sourceSizes')]
    public function a_software_clip_is_scaled_to_fit_720p(string $sourceSize, string $expectedSize): void
    {
        $source = $this->tmpPath.'source-'.$sourceSize.'.avi';
        $this->ffmpeg([
            '-f', 'lavfi', '-i', 'testsrc2=size='.$sourceSize.':rate=25:duration=1',
            '-c:v', 'mpeg4', $source,
        ]);

        $result = (new VideoClipEncoder(hardwareBackend: 'off'))
            ->encode($source, $this->tmpPath, (string) self::binary('ffmpeg'), 60, 'scale-guid');

        $this->assertNotNull($result);
        $this->assertSame($expectedSize, $this->probe($result->path, 'stream=width,height', 'x'));
    }

    #[Test]
    public function a_browser_safe_1080p_source_is_re_encoded_to_720p_and_capped_at_the_target(): void
    {
        $targetSeconds = 3;
        $source = $this->tmpPath.'source-h264-aac.mp4';
        $this->ffmpeg([
            '-f', 'lavfi', '-i', 'testsrc2=size=1920x1080:rate=25:duration=6',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=6',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-shortest', $source,
        ]);

        $result = (new VideoClipEncoder(hardwareBackend: 'off'))
            ->encode($source, $this->tmpPath, (string) self::binary('ffmpeg'), 60, 'safe-source-guid', $targetSeconds);

        $this->assertNotNull($result);
        $this->assertSame('mp4', $result->extension);
        $this->assertSame('1280x720', $this->probe($result->path, 'stream=width,height', 'x'));
        // The stored, rounded duration: AAC frame padding runs the file itself
        // slightly past the -t cap.
        $this->assertLessThanOrEqual($targetSeconds, $result->durationSeconds);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function ffmpeg(array $arguments): void
    {
        $command = [(string) self::binary('ffmpeg'), '-y', '-hide_banner', '-loglevel', 'error', ...$arguments];
        exec(implode(' ', array_map('escapeshellarg', $command)).' 2>/dev/null', $output, $status);
        $this->assertSame(0, $status, 'Could not build the source fixture.');
    }

    private function probe(string $path, string $entries, string $separator): string
    {
        exec(implode(' ', array_map('escapeshellarg', [
            (string) self::binary('ffprobe'), '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', $entries, '-of', 'csv=p=0:s='.$separator, $path,
        ])).' 2>/dev/null', $output);

        return trim(implode('', $output));
    }

    private static function binary(string $name): ?string
    {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));

        return $path === '' ? null : $path;
    }
}
