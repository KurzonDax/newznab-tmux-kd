<?php

declare(strict_types=1);

namespace Tests\Unit\AudioProcessing;

use App\Services\AdditionalProcessing\MediaTools;
use App\Services\AudioProcessing\AudioDecodableLengthProbe;
use FFMpeg\Driver\FFProbeDriver;
use FFMpeg\FFProbe;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

class AudioDecodableLengthProbeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_returns_the_last_decodable_audio_packet_timestamp(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'partial.flac';
        $driver = Mockery::mock(FFProbeDriver::class);
        $driver->shouldReceive('command')->once()->with([
            '-v',
            'error',
            '-select_streams',
            'a:0',
            '-show_entries',
            'packet=pts_time',
            '-of',
            'csv=p=0',
            $path,
        ])->andReturn("0.000000\n12.5\n42.25\nN/A\n");

        $this->assertSame(42.25, $this->probe($driver)->demuxedSeconds($path));
    }

    #[Test]
    public function command_failures_report_zero_decodable_seconds(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'broken.flac';
        $driver = Mockery::mock(FFProbeDriver::class);
        $driver->shouldReceive('command')->once()->andThrow(new RuntimeException('truncated input'));

        $this->assertSame(0.0, $this->probe($driver)->demuxedSeconds($path));
    }

    private function probe(FFProbeDriver $driver): AudioDecodableLengthProbe
    {
        $ffprobe = Mockery::mock(FFProbe::class);
        $ffprobe->shouldReceive('getFFProbeDriver')->andReturn($driver);

        $tools = new MediaTools;
        (new ReflectionProperty(MediaTools::class, 'ffprobe'))->setValue($tools, $ffprobe);

        return new AudioDecodableLengthProbe($tools);
    }
}
