<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\VideoDecodableLengthProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class VideoDecodableLengthProbeTest extends TestCase
{
    use CreatesProcessingConfiguration;

    #[Test]
    public function it_measures_the_playable_span_of_decodable_video_packets(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'partial-video.mp4';
        $commandSeen = null;
        $probe = new VideoDecodableLengthProbe(
            static function (array $command, int $timeout) use (&$commandSeen): string {
                $commandSeen = $command;

                return "10.000000,0.040000\n12.500000,0.040000\n17.000000,0.040000\nN/A,N/A\n";
            },
        );

        $seconds = $probe->demuxedSeconds(
            $path,
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
        );

        $this->assertEqualsWithDelta(7.04, $seconds, 0.000001);
        $this->assertSame([
            '/usr/bin/ffprobe',
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_entries',
            'packet=pts_time,duration_time',
            '-of',
            'csv=p=0',
            $path,
        ], $commandSeen);
    }

    #[Test]
    public function command_failures_report_an_unavailable_duration(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'broken-video.mp4';
        $probe = new VideoDecodableLengthProbe(
            static function (array $command, int $timeout): string {
                throw new RuntimeException('truncated input');
            },
        );

        $this->assertNull($probe->demuxedSeconds(
            $path,
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
        ));
    }
}
