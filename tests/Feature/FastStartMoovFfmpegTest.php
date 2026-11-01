<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AdditionalProcessing\Mp4MoovSplicer;
use App\Services\AdditionalProcessing\VideoHeadProbe;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

/**
 * Only ffmpeg can prove where a fast-start MP4 becomes readable, so this runs
 * the real binary on a real file and skips itself where it is absent.
 */
class FastStartMoovFfmpegTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::ffmpegBinary() === null) {
            $this->markTestSkipped('ffmpeg is required for this test.');
        }

        $this->tmpPath = $this->makeTempDirectory('fast-start-ffmpeg').'/';
    }

    #[Test]
    public function the_moov_end_marks_where_a_truncated_fast_start_mp4_gets_its_duration(): void
    {
        $bytes = $this->fastStartMp4();
        $moovStart = (int) strpos($bytes, 'moov') - 4;
        $splicer = new Mp4MoovSplicer;

        $moovEnd = $splicer->moovEndOffset(substr($bytes, 0, $moovStart + 8));

        $this->assertNotNull($moovEnd);
        $this->assertContains(
            substr($bytes, $moovEnd + 4, 4),
            ['free', 'mdat'],
            'The next top-level atom starts exactly at the moov end.',
        );

        $insideMoov = substr($bytes, 0, $moovStart + intdiv($moovEnd - $moovStart, 2));
        $this->assertSame($moovEnd, $splicer->moovEndOffset($insideMoov));
        $this->assertNull($this->probedDuration('inside.mp4', $insideMoov));

        $pastMoov = substr($bytes, 0, $moovEnd + 2048);
        $this->assertSame($moovEnd, $splicer->moovEndOffset($pastMoov));
        $this->assertEqualsWithDelta(2.0, $this->probedDuration('past.mp4', $pastMoov), 0.1);
    }

    private function fastStartMp4(): string
    {
        $path = $this->tmpPath.'source.mp4';
        $command = [
            (string) self::ffmpegBinary(), '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=25:duration=2',
            '-c:v', 'mpeg4', '-movflags', '+faststart', $path,
        ];
        exec(implode(' ', array_map('escapeshellarg', $command)).' 2>/dev/null', $output, $status);
        $this->assertSame(0, $status, 'Could not build the source fixture.');

        return (string) file_get_contents($path);
    }

    private function probedDuration(string $name, string $bytes): ?float
    {
        $path = $this->tmpPath.$name;
        file_put_contents($path, $bytes);

        return (new VideoHeadProbe)
            ->probe($path, $this->makeConfig(['ffmpegPath' => self::ffmpegBinary(), 'timeoutSeconds' => 60]))
            ?->durationSeconds;
    }

    private static function ffmpegBinary(): ?string
    {
        $path = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));

        return $path === '' ? null : $path;
    }
}
