<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\DTO\VideoHeadProbeResult;
use App\Services\AdditionalProcessing\VideoHeadProbe;
use PHPUnit\Framework\TestCase;

class VideoHeadProbeTest extends TestCase
{
    use CreatesProcessingConfiguration;

    public function test_it_parses_the_container_duration_and_reported_bitrate(): void
    {
        $probe = new VideoHeadProbe(
            static fn (array $command, int $timeout): string => "Input #0, matroska,webm, from 'media.avi':".PHP_EOL.
                '  Duration: 00:42:07.48, start: 0.000000, bitrate: 5372 kb/s',
        );

        $result = $probe->probe('/tmp/media.avi', $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']));

        $this->assertNotNull($result);
        $this->assertSame(2527.48, $result->durationSeconds);
        $this->assertSame(5372000, $result->bitsPerSecond);
    }

    public function test_it_returns_null_when_neither_duration_nor_bitrate_is_reported(): void
    {
        $probe = new VideoHeadProbe(
            static fn (array $command, int $timeout): string => 'Duration: N/A, bitrate: N/A',
        );

        $this->assertNull($probe->probe('/tmp/media.avi', $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg'])));
    }

    public function test_it_returns_null_when_the_probe_command_throws(): void
    {
        $probe = new VideoHeadProbe(
            static function (array $command, int $timeout): string {
                throw new \RuntimeException('ffmpeg failed');
            },
        );

        $this->assertNull($probe->probe('/tmp/media.avi', $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg'])));
    }

    public function test_bytes_per_second_prefers_the_full_file_size_over_the_partial_bitrate(): void
    {
        // 3000 total bytes over 30s beats the reported bitrate, which ffmpeg
        // computed from the partial file's size.
        $result = new VideoHeadProbeResult(durationSeconds: 30.0, bitsPerSecond: 400);
        $this->assertSame(100.0, $result->bytesPerSecond(3000));

        // Without a usable duration the reported bitrate is the fallback.
        $result = new VideoHeadProbeResult(durationSeconds: null, bitsPerSecond: 400);
        $this->assertSame(50.0, $result->bytesPerSecond(3000));

        // Without either signal there is no budget to compute.
        $result = new VideoHeadProbeResult(durationSeconds: 30.0, bitsPerSecond: 400);
        $this->assertSame(50.0, $result->bytesPerSecond(0));
        $result = new VideoHeadProbeResult;
        $this->assertNull($result->bytesPerSecond(3000));
    }
}
