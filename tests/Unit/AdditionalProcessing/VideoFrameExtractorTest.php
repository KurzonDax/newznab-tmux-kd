<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\VideoFrameExtractor;
use PHPUnit\Framework\TestCase;

class VideoFrameExtractorTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private string $framePath;

    private string $videoPath;

    protected function setUp(): void
    {
        parent::setUp();

        $videoPath = tempnam(sys_get_temp_dir(), 'nntmux-video-');
        $framePath = tempnam(sys_get_temp_dir(), 'nntmux-frame-');
        if ($videoPath === false || $framePath === false) {
            $this->fail('Unable to create temporary media paths.');
        }

        $this->videoPath = $videoPath;
        $this->framePath = $framePath;
        file_put_contents($this->videoPath, 'partial video');
    }

    protected function tearDown(): void
    {
        if (is_file($this->videoPath)) {
            unlink($this->videoPath);
        }
        if (is_file($this->framePath)) {
            unlink($this->framePath);
        }

        parent::tearDown();
    }

    public function test_it_uses_the_final_decoded_progress_time_for_a_safe_frame_timestamp(): void
    {
        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            static fn (array $command, int $timeout): string => 'frame=12 time=00:00:00.48 bitrate=N/A'.PHP_EOL.
                'frame=29 time=00:00:01.20 bitrate=N/A',
        );

        $duration = $extractor->probeDecodableDuration($this->videoPath);
        $timestamp = $extractor->representativeTimestamp($duration);

        $this->assertSame(1.2, $duration);
        $this->assertGreaterThanOrEqual(0.0, $timestamp);
        $this->assertLessThan(1.2, $timestamp);
        $this->assertNotSame(3.0, $timestamp);
    }

    public function test_it_never_rounds_a_tiny_frame_timestamp_beyond_the_decodable_duration(): void
    {
        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            static fn (array $command, int $timeout): string => 'frame=1 time=00:00:00.0007 bitrate=N/A',
        );

        $duration = $extractor->probeDecodableDuration($this->videoPath);
        $timestamp = $extractor->representativeTimestamp($duration);

        $this->assertSame(0.0007, $duration);
        $this->assertGreaterThanOrEqual(0.0, $timestamp);
        $this->assertLessThan($duration, $timestamp);
    }

    public function test_it_tries_the_near_end_seek_first_and_falls_back_through_the_remaining_strategies(): void
    {
        $attempts = [];
        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            function (array $command, int $timeout) use (&$attempts): string {
                if (in_array('null', $command, true)) {
                    return 'frame=30 time=00:00:01.20 bitrate=N/A';
                }

                $filterIndex = array_search('-vf', $command, true);
                if ($filterIndex !== false) {
                    $filter = $command[$filterIndex + 1];
                    $attempts[] = str_starts_with($filter, 'select=') ? 'scene' : 'thumbnail';

                    return '';
                }

                $seekIndex = array_search('-ss', $command, true);
                $timestamp = $seekIndex === false ? '' : $command[$seekIndex + 1];
                $attempts[] = 'timestamp:'.$timestamp;

                if ($timestamp === '0.000') {
                    $this->writeNonFlatJpeg($this->framePath);
                } else {
                    file_put_contents($this->framePath, 'not a jpeg');
                }

                return '';
            },
        );

        $this->assertTrue($extractor->extractRepresentativeFrame($this->videoPath, $this->framePath));
        $this->assertSame([
            'timestamp:1.020',
            'scene',
            'thumbnail',
            'timestamp:0.000',
        ], $attempts);
    }

    public function test_it_rejects_a_flat_frame_and_accepts_the_next_strategys_non_flat_frame(): void
    {
        $attempts = [];
        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            function (array $command, int $timeout) use (&$attempts): string {
                if (in_array('null', $command, true)) {
                    return 'frame=30 time=00:00:01.20 bitrate=N/A';
                }

                $filterIndex = array_search('-vf', $command, true);
                if ($filterIndex !== false) {
                    $attempts[] = 'scene-or-thumbnail';
                    $this->writeNonFlatJpeg($this->framePath);

                    return '';
                }

                $attempts[] = 'near-end';
                $this->writeFlatJpeg($this->framePath);

                return '';
            },
        );

        $this->assertTrue($extractor->extractRepresentativeFrame($this->videoPath, $this->framePath));
        $this->assertSame(['near-end', 'scene-or-thumbnail'], $attempts);
    }

    public function test_it_returns_false_when_every_strategy_yields_a_flat_frame(): void
    {
        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            function (array $command, int $timeout): string {
                if (in_array('null', $command, true)) {
                    return 'frame=30 time=00:00:01.20 bitrate=N/A';
                }

                $this->writeFlatJpeg($this->framePath);

                return '';
            },
        );

        $this->assertFalse($extractor->extractRepresentativeFrame($this->videoPath, $this->framePath));
    }

    private function writeNonFlatJpeg(string $path): void
    {
        $image = imagecreatetruecolor(64, 64);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 32, 0, 63, 63, $white);
        imagejpeg($image, $path);
    }

    private function writeFlatJpeg(string $path): void
    {
        $image = imagecreatetruecolor(64, 64);
        $nearBlack = imagecolorallocate($image, 12, 12, 12);
        imagefilledrectangle($image, 0, 0, 63, 63, $nearBlack);
        imagejpeg($image, $path);
    }

    public function test_it_returns_false_when_every_frame_strategy_throws(): void
    {
        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            static function (array $command, int $timeout): string {
                throw new \RuntimeException('ffmpeg failed');
            },
        );

        $this->assertFalse($extractor->extractRepresentativeFrame($this->videoPath, $this->framePath));
    }
}
