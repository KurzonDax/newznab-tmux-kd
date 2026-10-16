<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AdditionalProcessing\VideoClipEncoder;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Only ffmpeg can prove the clip scale filter parses and sizes the picture as
 * intended, so this runs the real binaries and skips itself where they are
 * absent. MPEG-4 Part 2 sources force the software transcode.
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

        $this->tmpPath = sys_get_temp_dir().'/clip-ffmpeg-'.bin2hex(random_bytes(6)).'/';
        (new Filesystem)->makeDirectory($this->tmpPath, 0777, true, true);
    }

    protected function tearDown(): void
    {
        // setUp() skips before this is set where ffmpeg is missing.
        if (isset($this->tmpPath)) {
            (new Filesystem)->deleteDirectory($this->tmpPath);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sourceSizes(): array
    {
        return [
            'larger than 1080p is fitted inside 1920x1080' => ['2560x1440', '1920x1080'],
            'portrait is fitted to 1080 high' => ['1080x2400', '486x1080'],
            'smaller than 1080p is not upscaled' => ['1280x720', '1280x720'],
        ];
    }

    #[Test]
    #[DataProvider('sourceSizes')]
    public function a_software_clip_is_scaled_to_fit_1080p(string $sourceSize, string $expectedSize): void
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
