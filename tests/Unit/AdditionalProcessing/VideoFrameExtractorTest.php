<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\MoovlessH264Stream;
use App\Services\AdditionalProcessing\VideoFrameExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VideoFrameExtractorTest extends TestCase
{
    use CreatesProcessingConfiguration;

    /** The width list, in search order; the first six are the pic_init_qp search's. */
    private const array WIDTHS = [
        3840, 1920, 1280, 960, 720, 1440, 854, 4096, 640, 2560, 1080,
        704, 980, 2048, 2880, 768, 3200, 2160, 1024, 1756, 4320, 848,
    ];

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

    public function test_the_width_search_rejects_bad_widths_and_uses_the_widest_that_fits(): void
    {
        $head = $this->x264Head('rc=crf crf=23.0');
        file_put_contents($this->videoPath, $head);
        $stream = $this->stream($head);
        $streamPath = $this->framePath.'.h264';
        $probeCommands = [];
        $frameStreams = [];

        $probeOutput = [
            3840 => $this->concealing(240, 256 - 45)."\n[h264 @ 0x1] error while decoding MB 3 4, bytestream -11",
            1920 => 'frame=    1 fps=0.0 q=-0.0 Lsize=N/A',
            1280 => $this->concealing(80, 256 - 45, extra: 1),
            960 => $this->concealing(60, 256 - 7),
            720 => $this->concealing(45, 256 - 34),
            1440 => $this->concealing(90, 256 - 68),
            854 => $this->concealing(54, 256 - 30),
        ];

        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg', 'timeoutSeconds' => 42]),
            function (array $command, int $timeout) use (&$probeCommands, &$frameStreams, $probeOutput, $streamPath): string {
                $this->assertSame(42, $timeout);
                if (in_array('null', $command, true)) {
                    $probeCommands[] = $command;

                    return $probeOutput[self::WIDTHS[count($probeCommands) - 1]] ?? '';
                }

                $frameStreams[] = (string) file_get_contents($streamPath);
                $this->writeNonFlatJpeg($this->framePath);

                return '';
            },
        );

        $this->assertTrue($extractor->extractMoovlessFrame($this->videoPath, $this->framePath));
        $this->assertCount(count(self::WIDTHS), $probeCommands);
        $this->assertSame(
            ['/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-v', 'info', '-f', 'h264', '-i', $streamPath, '-frames:v', '1', '-f', 'null', '-'],
            $probeCommands[0],
        );
        $this->assertSame(
            [$stream->rebuiltSettings(1440, 68).$stream->keyframeGroups()[2]],
            $frameStreams,
            'The widest fitting width with its measured rows is used, on the last keyframe group.',
        );
        $this->assertFileDoesNotExist($streamPath);
    }

    public function test_the_width_probe_writes_the_first_group_behind_a_256_row_sps(): void
    {
        $head = $this->x264Head('rc=crf crf=23.0');
        file_put_contents($this->videoPath, $head);
        $stream = $this->stream($head);
        $streamPath = $this->framePath.'.h264';
        $probeStreams = [];

        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            function (array $command, int $timeout) use (&$probeStreams, $streamPath): string {
                $probeStreams[] = (string) file_get_contents($streamPath);

                return '';
            },
        );

        $this->assertFalse($extractor->extractMoovlessFrame($this->videoPath, $this->framePath));
        $this->assertSame($stream->rebuiltSettings(3840, 256).$stream->keyframeGroups()[0], $probeStreams[0]);
        $this->assertSame($stream->rebuiltSettings(848, 256).$stream->keyframeGroups()[0], $probeStreams[21]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedProbeOutputs(): array
    {
        return [
            'too few rows' => ['concealing '.((120 * 256) - (120 * 7)).' DC, 0 AC, 0 MV errors in I frame'],
            'nothing decoded' => ['concealing '.(120 * 256).' DC, 0 AC, 0 MV errors in I frame'],
            'not a whole row' => ['concealing 121 DC, 0 AC, 0 MV errors in I frame'],
            'cabac error' => ['[h264] cabac decode of qscale diff failed'."\n".'concealing '.(120 * 188).' DC, 0 AC, 0 MV errors in I frame'],
        ];
    }

    #[DataProvider('rejectedProbeOutputs')]
    public function test_a_width_with_a_bad_probe_result_does_not_fit(string $output): void
    {
        file_put_contents($this->videoPath, $this->x264Head('rc=crf crf=23.0'));

        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            static function (array $command, int $timeout) use ($output): string {
                return $output;
            },
        );

        $this->assertFalse($extractor->extractMoovlessFrame($this->videoPath, $this->framePath));
        $this->assertFileDoesNotExist($this->framePath.'.h264');
    }

    public function test_the_pic_init_qp_search_does_not_run_outside_two_pass(): void
    {
        file_put_contents($this->videoPath, $this->x264Head('rc=abr bitrate=1000'));
        $probes = 0;

        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            static function (array $command, int $timeout) use (&$probes): string {
                $probes++;

                return '';
            },
        );

        $this->assertFalse($extractor->extractMoovlessFrame($this->videoPath, $this->framePath));
        $this->assertSame(count(self::WIDTHS), $probes);
    }

    public function test_the_pic_init_qp_search_does_not_run_when_a_two_pass_width_fits(): void
    {
        $head = $this->x264Head('rc=2pass bitrate=1000');
        file_put_contents($this->videoPath, $head);
        $stream = $this->stream($head);
        $probes = 0;
        $frameStream = null;

        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            function (array $command, int $timeout) use (&$probes, &$frameStream): string {
                if (in_array('null', $command, true)) {
                    $probes++;

                    return $probes === 2 ? $this->concealing(120, 256 - 68) : '';
                }
                $frameStream = (string) file_get_contents($this->framePath.'.h264');
                $this->writeNonFlatJpeg($this->framePath);

                return '';
            },
        );

        $this->assertTrue($extractor->extractMoovlessFrame($this->videoPath, $this->framePath));
        $this->assertSame(count(self::WIDTHS), $probes);
        $this->assertSame($stream->rebuiltSettings(1920, 68, 26).$stream->keyframeGroups()[2], $frameStream);
    }

    public function test_the_two_pass_search_covers_the_first_six_widths_and_stops_at_the_first_value_that_fits(): void
    {
        $head = $this->x264Head('rc=2pass bitrate=1000');
        file_put_contents($this->videoPath, $head);
        $stream = $this->stream($head);
        $firstGroup = $stream->keyframeGroups()[0];
        $searchStreams = [];
        $probes = 0;
        $frameStream = null;

        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            function (array $command, int $timeout) use (&$searchStreams, &$probes, &$frameStream, $stream, $firstGroup): string {
                $written = (string) file_get_contents($this->framePath.'.h264');
                if (! in_array('null', $command, true)) {
                    $frameStream = $written;
                    $this->writeNonFlatJpeg($this->framePath);

                    return '';
                }

                $probes++;
                if ($probes > count(self::WIDTHS)) {
                    $searchStreams[] = $written;
                }

                return $written === $stream->rebuiltSettings(1280, 256, 17).$firstGroup
                    ? $this->concealing(80, 256 - 45)
                    : '';
            },
        );

        $this->assertTrue($extractor->extractMoovlessFrame($this->videoPath, $this->framePath));

        $expectedSearch = [];
        foreach (range(10, 17) as $picInitQp) {
            foreach ([3840, 1920, 1280, 960, 720, 1440] as $width) {
                $expectedSearch[] = $stream->rebuiltSettings($width, 256, $picInitQp).$firstGroup;
            }
        }
        $this->assertSame($expectedSearch, $searchStreams);
        $this->assertSame($stream->rebuiltSettings(1280, 45, 17).$stream->keyframeGroups()[2], $frameStream);
    }

    public function test_a_two_pass_file_with_no_fitting_value_returns_false_after_every_value(): void
    {
        file_put_contents($this->videoPath, $this->x264Head('rc=2pass bitrate=1000'));
        $probes = 0;

        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            static function (array $command, int $timeout) use (&$probes): string {
                $probes++;

                return '';
            },
        );

        $this->assertFalse($extractor->extractMoovlessFrame($this->videoPath, $this->framePath));
        $this->assertSame(count(self::WIDTHS) + (6 * 31), $probes);
    }

    public function test_keyframe_groups_are_tried_last_to_first_and_a_flat_or_concealed_frame_moves_earlier(): void
    {
        $head = $this->inBandHead();
        file_put_contents($this->videoPath, $head);
        $stream = $this->stream($head);
        [$first, $second, $third] = $stream->keyframeGroups();
        $settings = $stream->inBandSettings();
        $streamPath = $this->framePath.'.h264';
        $streams = [];
        $commands = [];

        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            function (array $command, int $timeout) use (&$streams, &$commands, $streamPath): string {
                $this->assertFileDoesNotExist($this->framePath, 'A rejected frame must not survive into the next run.');
                $streams[] = (string) file_get_contents($streamPath);
                $commands[] = $command;

                if (count($streams) === 1) {
                    $this->writeFlatJpeg($this->framePath);

                    return '';
                }

                $this->writeNonFlatJpeg($this->framePath);

                return count($streams) === 2 ? 'concealing 12 DC, 12 AC, 12 MV errors in I frame' : '';
            },
        );

        $this->assertTrue($extractor->extractMoovlessFrame($this->videoPath, $this->framePath));
        $this->assertSame([$settings.$third, $settings.$second, $settings.$first], $streams);
        $this->assertSame(
            ['/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-v', 'info', '-y', '-f', 'h264', '-i', $streamPath, '-frames:v', '300', '-fps_mode', 'passthrough', '-q:v', '2', '-update', '1', $this->framePath],
            $commands[0],
        );
        $this->assertFileDoesNotExist($streamPath);
    }

    public function test_a_decoder_error_line_rejects_a_group(): void
    {
        $head = $this->inBandHead();
        file_put_contents($this->videoPath, $head);
        $runs = 0;

        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            function (array $command, int $timeout) use (&$runs): string {
                $runs++;
                $this->writeNonFlatJpeg($this->framePath);

                return $runs === 1 ? '[h264 @ 0x1] Invalid mb_type' : '';
            },
        );

        $this->assertTrue($extractor->extractMoovlessFrame($this->videoPath, $this->framePath));
        $this->assertSame(2, $runs);
    }

    public function test_a_path_that_spells_a_decoder_error_word_is_not_a_decoder_error(): void
    {
        file_put_contents($this->videoPath, $this->inBandHead());
        $runs = 0;

        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            function (array $command, int $timeout) use (&$runs): string {
                $runs++;
                $this->writeNonFlatJpeg($this->framePath);

                return "Input #0, h264, from '".$this->framePath.".h264':\nOutput #0, image2, to '".$this->framePath."':";
            },
        );

        $framePath = $this->framePath;
        $this->framePath = $framePath.'-cabac';
        try {
            $this->assertTrue($extractor->extractMoovlessFrame($this->videoPath, $this->framePath));
        } finally {
            @unlink($this->framePath);
            $this->framePath = $framePath;
        }
        $this->assertSame(1, $runs);
    }

    public function test_the_exit_on_error_run_comes_only_after_every_group_fails_and_keeps_in_band_settings_in_place(): void
    {
        $head = $this->inBandHead();
        file_put_contents($this->videoPath, $head);
        $stream = $this->stream($head);
        $streamPath = $this->framePath.'.h264';
        $commands = [];
        $streams = [];

        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            function (array $command, int $timeout) use (&$commands, &$streams, $streamPath): string {
                $commands[] = $command;
                $streams[] = (string) file_get_contents($streamPath);

                if (in_array('-xerror', $command, true)) {
                    $this->writeNonFlatJpeg($this->framePath);

                    return 'error while decoding MB 1 1, concealing 5 DC, 5 AC, 5 MV errors in I frame';
                }

                return '';
            },
        );

        $this->assertTrue($extractor->extractMoovlessFrame($this->videoPath, $this->framePath));
        $this->assertCount(4, $commands);
        foreach (array_slice($commands, 0, 3) as $command) {
            $this->assertNotContains('-xerror', $command);
        }
        $this->assertSame(
            ['/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-v', 'info', '-y', '-xerror', '-f', 'h264', '-i', $streamPath, '-frames:v', '300', '-fps_mode', 'passthrough', '-q:v', '2', '-update', '1', $this->framePath],
            $commands[3],
        );
        $this->assertSame($stream->sliceStream(), $streams[3]);
        $this->assertFileDoesNotExist($streamPath);
    }

    public function test_the_exit_on_error_run_on_the_x264_route_writes_the_rebuilt_settings_first(): void
    {
        $head = $this->x264Head('rc=crf crf=23.0');
        file_put_contents($this->videoPath, $head);
        $stream = $this->stream($head);
        $exitOnErrorStream = null;

        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            function (array $command, int $timeout) use (&$exitOnErrorStream, $stream): string {
                if (in_array('null', $command, true)) {
                    return file_get_contents($this->framePath.'.h264') === $stream->rebuiltSettings(1280, 256).$stream->keyframeGroups()[0]
                        ? $this->concealing(80, 256 - 45)
                        : '';
                }
                if (in_array('-xerror', $command, true)) {
                    $exitOnErrorStream = (string) file_get_contents($this->framePath.'.h264');
                    $this->writeNonFlatJpeg($this->framePath);
                }

                return '';
            },
        );

        $this->assertTrue($extractor->extractMoovlessFrame($this->videoPath, $this->framePath));
        $this->assertSame($stream->rebuiltSettings(1280, 45).$stream->sliceStream(), $exitOnErrorStream);
    }

    public function test_a_file_left_at_the_frame_path_by_an_earlier_attempt_is_not_accepted(): void
    {
        file_put_contents($this->videoPath, $this->inBandHead());
        $this->writeNonFlatJpeg($this->framePath);

        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            static fn (array $command, int $timeout): string => '',
        );

        $this->assertFalse($extractor->extractMoovlessFrame($this->videoPath, $this->framePath));
        $this->assertFileDoesNotExist($this->framePath.'.h264');
    }

    public function test_a_flat_exit_on_error_frame_returns_false(): void
    {
        file_put_contents($this->videoPath, $this->inBandHead());

        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            function (array $command, int $timeout): string {
                $this->writeFlatJpeg($this->framePath);

                return '';
            },
        );

        $this->assertFalse($extractor->extractMoovlessFrame($this->videoPath, $this->framePath));
    }

    public function test_a_head_with_no_route_runs_nothing(): void
    {
        file_put_contents($this->videoPath, 'partial video');
        $runs = 0;

        $extractor = new VideoFrameExtractor(
            $this->makeConfig(['ffmpegPath' => '/usr/bin/ffmpeg']),
            static function (array $command, int $timeout) use (&$runs): string {
                $runs++;

                return '';
            },
        );

        $this->assertFalse($extractor->extractMoovlessFrame($this->videoPath, $this->framePath));
        $this->assertSame(0, $runs);
    }

    /**
     * ffmpeg's report for a picture with $rows of its 256 macroblock rows missing,
     * plus $extra single macroblocks.
     */
    private function concealing(int $macroblockWidth, int $rows, int $extra = 0): string
    {
        return '[h264 @ 0x1] concealing '.(($macroblockWidth * $rows) + $extra).' DC, 0 AC, 0 MV errors in I frame';
    }

    private function stream(string $head): MoovlessH264Stream
    {
        $stream = MoovlessH264Stream::fromHead($head);
        $this->assertNotNull($stream);

        return $stream;
    }

    private function x264Head(string $rateControl): string
    {
        return $this->mdatHead($this->nal("\x06", "\x05\xFF".str_repeat('u', 16)
            .'x264 - core 164 r3108 31e19f9 - H.264/MPEG-4 AVC codec - Copyleft 2003-2023 - options: '
            .'cabac=1 ref=3 deblock=1:0:0 analyse=0x3:0x113 me=hex subme=7 8x8dct=1 cqm=0 chroma_qp_offset=-2 '
            .'bframes=3 b_pyramid=2 weightb=1 weightp=2 interlaced=0 '.$rateControl."\x00\x80").$this->keyframes());
    }

    private function inBandHead(): string
    {
        return $this->mdatHead($this->nal("\x67", 'sps').$this->nal("\x68", 'pps').$this->keyframes());
    }

    private function keyframes(): string
    {
        return $this->nal("\x65", "\x88".str_pad('group one', 20, '.'))
            .$this->nal("\x41", "\x9A".str_pad('predicted one', 20, '.'))
            .$this->nal("\x65", "\x88".str_pad('group two', 20, '.'))
            .$this->nal("\x65", "\x88".str_pad('group three', 20, '.'));
    }

    private function mdatHead(string $payload): string
    {
        return pack('N', 16).'ftypisom0000'.pack('N', strlen($payload) + 100_000).'mdat'.$payload;
    }

    private function nal(string $header, string $payload): string
    {
        return pack('N', strlen($header.$payload)).$header.$payload;
    }
}
