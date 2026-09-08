<?php

declare(strict_types=1);

namespace Tests\Integration;

use Mhor\MediaInfo\MediaInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ObfuscationRecovery\LocalMediaFixture;
use Tests\TestCase;

final class RecoveryMediaFixtureTest extends TestCase
{
    #[DataProvider('formats')]
    public function test_mandatory_container_heads_are_parsed_by_the_actual_media_reader(string $format): void
    {
        $root = $this->makeTempDirectory('recovery-media');
        $full = LocalMediaFixture::write($root, $format);
        if ($format === 'mp4') {
            $this->assertLessThan(16384, LocalMediaFixture::mp4AtomOffsets($full)['moov']);
        }
        $head = $root.'/bounded.'.$format;
        file_put_contents($head, file_get_contents($full, length: 16384));
        $reader = new MediaInfo;
        $reader->setConfig('command', '/usr/bin/mediainfo');
        $complete = $reader->getInfo($full, true);
        $bounded = $reader->getInfo($head, true);
        $this->assertCount(1, $complete->getVideos());
        $this->assertCount(1, $bounded->getVideos());
        $this->assertSame(16384, filesize($head));
        (new Process(['/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-i', $full, '-f', 'null', '-']))->setTimeout(30)->mustRun();
    }

    public function test_mp4_metadata_after_the_bounded_head_is_explicitly_unavailable(): void
    {
        $root = $this->makeTempDirectory('recovery-tail-metadata');
        $full = LocalMediaFixture::write($root, 'mp4', false);
        $this->assertGreaterThan(2097152, filesize($full));
        $this->assertGreaterThan(2097152, LocalMediaFixture::mp4AtomOffsets($full)['moov']);
        $head = $root.'/bounded.mp4';
        file_put_contents($head, file_get_contents($full, length: 2097152));
        $reader = new MediaInfo;
        $reader->setConfig('command', '/usr/bin/mediainfo');
        $this->assertCount(1, $reader->getInfo($full, true)->getVideos());
        $this->assertSame([], $reader->getInfo($head, true)->getVideos());
    }

    public static function formats(): array
    {
        return [['mkv'], ['mp4']];
    }
}
