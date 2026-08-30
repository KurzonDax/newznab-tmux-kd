<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\VideoClipEncoder;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

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

        $result = $encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, '/usr/bin/ffmpeg', 60, 'test-guid');

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
        $this->assertNotContains('-t', $remux, 'Stream-copy clips keep the whole downloaded head window.');
        $this->assertStringEndsWith('.mp4', (string) end($remux));
    }

    public function test_divx_with_mpeg_audio_transcodes_to_a_capped_h264_aac_mp4(): void
    {
        $commands = [];
        $encoder = new VideoClipEncoder(function (array $command, int $timeout) use (&$commands): string {
            $commands[] = $command;
            if (in_array('libx264', $command, true)) {
                file_put_contents(end($command), 'transcoded clip bytes');

                return '';
            }

            if (str_ends_with((string) end($command), 'source.bin')) {
                return "Stream #0:0: Video: mpeg4 (Advanced Simple Profile)\n  Stream #0:1: Audio: mp3, 48000 Hz";
            }

            return 'Duration: 00:00:17.00, start: 0.000000, bitrate: 2000 kb/s';
        });

        $result = $encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, 'ffmpeg', 60, 'test-guid', 17);

        $this->assertNotNull($result);
        $this->assertSame('mp4', $result->extension);
        $this->assertSame('video/mp4', $result->mime);
        $this->assertSame(17, $result->durationSeconds);

        $transcode = $commands[1];
        $this->assertCommandOption($transcode, '-c:v', 'libx264');
        $this->assertCommandOption($transcode, '-preset', 'veryfast');
        $this->assertCommandOption($transcode, '-crf', '23');
        $this->assertCommandOption($transcode, '-c:a', 'aac');
        $this->assertCommandOption($transcode, '-t', '17');
        $this->assertContains('+faststart', $transcode);
    }

    public function test_an_unsafe_video_codec_logs_the_codec_when_its_fallback_declines(): void
    {
        Log::spy();
        $encoder = new VideoClipEncoder(function (array $command, int $timeout): string {
            if (str_ends_with((string) end($command), 'source.bin')) {
                return "Stream #0:0: Video: hevc (Main)\n  Stream #0:1: Audio: aac (LC)";
            }

            throw new RuntimeException('fallback decode failed');
        });

        $result = $encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, 'ffmpeg', 60, 'unsafe-video-guid');

        $this->assertNull($result);
        Log::shouldHaveReceived('debug')->once()->with(
            'Clip generation declined',
            [
                'release_guid' => 'unsafe-video-guid',
                'reason' => 'clip_unsafe_video_codec',
                'video_codec' => 'hevc',
                'failure_reason' => 'clip_remux_failed',
                'exception_message' => 'fallback decode failed',
            ],
        );
    }

    public function test_h264_with_ac3_copies_video_and_only_transcodes_audio(): void
    {
        $commands = [];
        $encoder = new VideoClipEncoder(function (array $command, int $timeout) use (&$commands): string {
            $commands[] = $command;
            if (in_array('-c:v', $command, true)) {
                file_put_contents(end($command), 'mixed clip bytes');

                return '';
            }

            if (str_ends_with((string) end($command), 'source.bin')) {
                return "Stream #0:0: Video: h264 (High)\n  Stream #0:1: Audio: ac3, 48000 Hz";
            }

            return 'Duration: 00:00:12.00, start: 0.000000, bitrate: 3000 kb/s';
        });

        $result = $encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, 'ffmpeg', 60, 'test-guid', 12);

        $this->assertNotNull($result);
        $transcode = $commands[1];
        $this->assertCommandOption($transcode, '-c:v', 'copy');
        $this->assertCommandOption($transcode, '-c:a', 'aac');
        $this->assertCommandOption($transcode, '-t', '12');
        $this->assertNotContains('libx264', $transcode);
    }

    public function test_an_unsafe_audio_codec_logs_the_codec_when_its_fallback_declines(): void
    {
        Log::spy();
        $encoder = new VideoClipEncoder(function (array $command, int $timeout): string {
            if (str_ends_with((string) end($command), 'source.bin')) {
                return "Stream #0:0: Video: h264 (High)\n  Stream #0:1: Audio: ac3, 48000 Hz";
            }

            throw new RuntimeException('audio fallback failed');
        });

        $result = $encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, 'ffmpeg', 60, 'unsafe-audio-guid');

        $this->assertNull($result);
        Log::shouldHaveReceived('debug')->once()->with(
            'Clip generation declined',
            [
                'release_guid' => 'unsafe-audio-guid',
                'reason' => 'clip_unsafe_audio_codec',
                'audio_codec' => 'ac3',
                'failure_reason' => 'clip_remux_failed',
                'exception_message' => 'audio fallback failed',
            ],
        );
    }

    public function test_a_zero_preview_target_leaves_the_fallback_transcode_uncapped(): void
    {
        $commands = [];
        $encoder = new VideoClipEncoder(function (array $command, int $timeout) use (&$commands): string {
            $commands[] = $command;
            if (in_array('libx264', $command, true)) {
                file_put_contents(end($command), 'uncapped transcode bytes');

                return '';
            }

            return 'Stream #0:0: Video: hevc (Main)';
        });

        $this->assertNotNull($encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, 'ffmpeg', 60, 'test-guid', 0));
        $this->assertNotContains('-t', $commands[1]);
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

        $result = $encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, 'ffmpeg', 60, 'test-guid');

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

        $result = $encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, 'ffmpeg', 60, 'test-guid');

        $this->assertNotNull($result);
        $this->assertSame('mp4', $result->extension);
        $this->assertNotContains('0:a:0', $commands[1]);
    }

    public function test_a_source_without_video_is_refused_without_running_an_encode(): void
    {
        $commandCount = 0;
        Log::spy();
        $encoder = new VideoClipEncoder(function (array $command, int $timeout) use (&$commandCount): string {
            $commandCount++;

            return 'Stream #0:0: Audio: aac (LC)';
        });

        $this->assertNull($encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, 'ffmpeg', 60, 'probe-failure-guid'));
        $this->assertSame(1, $commandCount);
        Log::shouldHaveReceived('debug')->once()->with(
            'Clip generation declined',
            [
                'release_guid' => 'probe-failure-guid',
                'reason' => 'clip_probe_failed',
            ],
        );
    }

    public function test_a_probe_exception_logs_probe_failed(): void
    {
        Log::spy();
        $encoder = new VideoClipEncoder(
            static fn (array $command, int $timeout): never => throw new RuntimeException('probe process failed'),
        );

        $this->assertNull($encoder->encode(
            $this->tmpPath.'source.bin',
            $this->tmpPath,
            'ffmpeg',
            60,
            'probe-exception-guid',
        ));
        Log::shouldHaveReceived('debug')->once()->with(
            'Clip generation declined',
            [
                'release_guid' => 'probe-exception-guid',
                'reason' => 'clip_probe_failed',
            ],
        );
    }

    public function test_an_empty_remux_output_is_refused(): void
    {
        Log::spy();
        $encoder = new VideoClipEncoder(function (array $command, int $timeout): string {
            if (in_array('copy', $command, true)) {
                file_put_contents(end($command), '');

                return '';
            }

            return 'Stream #0:0: Video: h264 (High)';
        });

        $this->assertNull($encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, 'ffmpeg', 60, 'empty-output-guid'));
        Log::shouldHaveReceived('debug')->once()->with(
            'Clip generation declined',
            [
                'release_guid' => 'empty-output-guid',
                'reason' => 'clip_empty_output',
            ],
        );
    }

    public function test_a_remux_exception_logs_the_failure_message(): void
    {
        Log::spy();
        $encoder = new VideoClipEncoder(function (array $command, int $timeout): string {
            if (str_ends_with((string) end($command), 'source.bin')) {
                return 'Stream #0:0: Video: h264 (High)';
            }

            throw new RuntimeException('ffmpeg timed out after 60 seconds');
        });

        $result = $encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, 'ffmpeg', 60, 'remux-failure-guid');

        $this->assertNull($result);
        Log::shouldHaveReceived('debug')->once()->with(
            'Clip generation declined',
            [
                'release_guid' => 'remux-failure-guid',
                'reason' => 'clip_remux_failed',
                'exception_message' => 'ffmpeg timed out after 60 seconds',
            ],
        );
    }

    public function test_a_timed_out_encode_discards_its_partial_output(): void
    {
        Log::spy();
        $fakeFfmpeg = $this->tmpPath.'fake-ffmpeg';
        file_put_contents($fakeFfmpeg, <<<'SHELL'
#!/bin/sh
case "$*" in
  *"-loglevel error"*)
    for output_path do true; done
    printf 'partial clip bytes' > "$output_path"
    sleep 2
    ;;
  *)
    printf 'Stream #0:0: Video: h264 (High)\n' >&2
    ;;
esac
SHELL);
        chmod($fakeFfmpeg, 0777);

        $encoder = new VideoClipEncoder;

        $this->assertNull($encoder->encode($this->tmpPath.'source.bin', $this->tmpPath, $fakeFfmpeg, 1, 'timeout-guid'));
        $this->assertSame([], glob($this->tmpPath.'clip_*') ?: []);
        Log::shouldHaveReceived('debug')->once()->with(
            'Clip generation declined',
            \Mockery::on(static fn (array $context): bool => $context['release_guid'] === 'timeout-guid'
                && $context['reason'] === 'clip_remux_failed'
                && str_contains((string) $context['exception_message'], 'exceeded the timeout of 1 seconds')),
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function assertCommandOption(array $command, string $option, string $expectedValue): void
    {
        $position = array_search($option, $command, true);

        $this->assertIsInt($position, 'Command option '.$option.' was not emitted.');
        $this->assertSame($expectedValue, $command[$position + 1] ?? null);
    }
}
