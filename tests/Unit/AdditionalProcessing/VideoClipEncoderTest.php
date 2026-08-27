<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\VideoClipEncoder;
use PHPUnit\Framework\TestCase;

class VideoClipEncoderTest extends TestCase
{
    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpPath = sys_get_temp_dir().'/nntmux-clip-'.uniqid('', true).'/';
        mkdir($this->tmpPath, 0777, true);
        file_put_contents($this->tmpPath.'source.bin', 'source video head');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpPath.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpPath);

        parent::tearDown();
    }

    public function test_h264_with_aac_remuxes_to_faststart_mp4(): void
    {
        $commands = [];
        $encoder = new VideoClipEncoder(function (array $command, int $timeout) use (&$commands): string {
            $commands[] = $command;
            if (in_array('copy', $command, true)) {
                file_put_contents(end($command), 'remuxed clip bytes');

                return '';
            }

            if (str_ends_with((string) $command[count($command) - 1], 'source.bin')) {
                return "Duration: 00:00:42.50, start: 0.000000, bitrate: 5000 kb/s\n"
                    ."  Stream #0:0(und): Video: h264 (High) (avc1 / 0x31637661)\n"
                    .'  Stream #0:1(und): Audio: aac (LC), 48000 Hz, stereo';
            }

            return 'Duration: 00:00:30.20, start: 0.000000, bitrate: 5000 kb/s';
        });

        $result = $encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, '/usr/bin/ffmpeg', 60);

        $this->assertNotNull($result);
        $this->assertSame('mp4', $result->extension);
        $this->assertSame('video/mp4', $result->mime);
        $this->assertSame(30, $result->durationSeconds);
        $this->assertSame(strlen('remuxed clip bytes'), $result->bytes);

        $remux = $commands[1];
        $this->assertContains('-c', $remux);
        $this->assertContains('copy', $remux);
        $this->assertContains('+faststart', $remux);
        $this->assertContains('0:a:0', $remux, 'The AAC track is mapped into the MP4.');
        $this->assertStringEndsWith('.mp4', (string) end($remux));
    }

    public function test_vp9_with_opus_remuxes_to_webm_without_faststart(): void
    {
        $commands = [];
        $encoder = new VideoClipEncoder(function (array $command, int $timeout) use (&$commands): string {
            $commands[] = $command;
            if (in_array('copy', $command, true)) {
                file_put_contents(end($command), 'webm clip');

                return '';
            }

            return "Stream #0:0: Video: vp9 (Profile 0)\n  Stream #0:1: Audio: opus, 48000 Hz";
        });

        $result = $encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, 'ffmpeg', 60);

        $this->assertNotNull($result);
        $this->assertSame('webm', $result->extension);
        $this->assertSame('video/webm', $result->mime);
        $this->assertNotContains('+faststart', $commands[1]);
    }

    public function test_video_without_audio_is_remuxed_with_no_audio_map(): void
    {
        $commands = [];
        $encoder = new VideoClipEncoder(function (array $command, int $timeout) use (&$commands): string {
            $commands[] = $command;
            if (in_array('copy', $command, true)) {
                file_put_contents(end($command), 'silent clip');

                return '';
            }

            return 'Stream #0:0: Video: h264 (Main)';
        });

        $result = $encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, 'ffmpeg', 60);

        $this->assertNotNull($result);
        $this->assertSame('mp4', $result->extension);
        $this->assertNotContains('0:a:0', $commands[1]);
    }

    public function test_browser_unsafe_streams_are_refused_without_running_a_remux(): void
    {
        $probeOutputs = [
            'h265' => "Stream #0:0: Video: hevc (Main)\n  Stream #0:1: Audio: aac (LC)",
            'mpeg2' => 'Stream #0:0: Video: mpeg2video (Main)',
            'ac3-audio' => "Stream #0:0: Video: h264 (High)\n  Stream #0:1: Audio: ac3, 48000 Hz",
            'vorbis-in-mp4' => "Stream #0:0: Video: h264 (High)\n  Stream #0:1: Audio: vorbis",
            'audio-only' => 'Stream #0:0: Audio: aac (LC)',
        ];

        foreach ($probeOutputs as $label => $probeOutput) {
            $commandCount = 0;
            $encoder = new VideoClipEncoder(function (array $command, int $timeout) use (&$commandCount, $probeOutput): string {
                $commandCount++;

                return $probeOutput;
            });

            $this->assertNull(
                $encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, 'ffmpeg', 60),
                $label.' must fall back to the transcode path',
            );
            $this->assertSame(1, $commandCount, $label.' must only probe, never remux');
        }
    }

    public function test_an_empty_remux_output_is_refused(): void
    {
        $encoder = new VideoClipEncoder(function (array $command, int $timeout): string {
            if (in_array('copy', $command, true)) {
                file_put_contents(end($command), '');

                return '';
            }

            return 'Stream #0:0: Video: h264 (High)';
        });

        $this->assertNull($encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, 'ffmpeg', 60));
    }
}
