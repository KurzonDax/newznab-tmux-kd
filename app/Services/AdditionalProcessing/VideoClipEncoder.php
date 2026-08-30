<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Enums\ClipGenerationDeclineReason;
use App\Services\AdditionalProcessing\DTO\VideoClipEncodeResult;
use Closure;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Produces the Clip (see CONTEXT.md): a browser-safe remux where possible,
 * otherwise a capped MP4 transcode of the downloaded head window.
 *
 * H.264 with AAC or no audio remuxes into MP4; VP8/VP9 with Vorbis/Opus or no
 * audio remuxes into WebM. Other decodable video falls back to H.264/AAC MP4.
 */
class VideoClipEncoder
{
    /**
     * Browser-safe video codecs mapped to the container they remux into.
     */
    private const array VIDEO_CODEC_CONTAINERS = [
        'h264' => 'mp4',
        'vp8' => 'webm',
        'vp9' => 'webm',
    ];

    /**
     * Audio codecs each container can carry; audio outside this list makes
     * the source unsafe (no audio at all is always safe).
     */
    private const array CONTAINER_AUDIO_CODECS = [
        'mp4' => ['aac'],
        'webm' => ['vorbis', 'opus'],
    ];

    private const array CONTAINER_MIME_TYPES = [
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    /**
     * @var Closure(list<string>, int): string
     */
    private readonly Closure $commandRunner;

    /**
     * @param  (callable(list<string>, int): string)|null  $commandRunner
     */
    public function __construct(
        ?callable $commandRunner = null,
        private readonly SoftwareClipTranscodeArguments $softwareTranscodeArguments = new SoftwareClipTranscodeArguments,
    ) {
        $this->commandRunner = $commandRunner === null
            ? Closure::fromCallable([$this, 'runProcess'])
            : Closure::fromCallable($commandRunner);
    }

    /**
     * Encode the source into a browser-safe Clip in $tmpPath, or return null
     * when there is no recognizable video stream or the encode fails.
     */
    public function encode(
        string $sourcePath,
        string $tmpPath,
        string $ffmpegBinary,
        int $timeoutSeconds,
        string $releaseGuid,
        int $previewTargetSeconds = 30,
    ): ?VideoClipEncodeResult {
        $streams = $this->probeStreams($sourcePath, $ffmpegBinary, $timeoutSeconds);
        if ($streams === null) {
            ClipGenerationLog::declined($releaseGuid, ClipGenerationDeclineReason::ProbeFailed);

            return null;
        }

        $container = self::VIDEO_CODEC_CONTAINERS[$streams['video']] ?? null;
        $unsafeVideoCodec = $container === null ? $streams['video'] : null;
        $hasAudio = $streams['audio'] !== null;
        $unsafeAudioCodec = $container !== null
            && $hasAudio
            && ! in_array($streams['audio'], self::CONTAINER_AUDIO_CODECS[$container], true)
                ? $streams['audio']
                : null;
        $streamCopy = $container !== null
            && $unsafeAudioCodec === null;
        $container = $streamCopy ? $container : 'mp4';

        $outputPath = $tmpPath.'clip_'.uniqid('', true).'.'.$container;
        $command = [
            $ffmpegBinary,
            '-y',
            '-hide_banner',
            '-nostdin',
            '-loglevel',
            'error',
            '-i',
            $sourcePath,
            '-map',
            '0:v:0',
        ];
        if ($hasAudio) {
            $command = [...$command, '-map', '0:a:0'];
        }
        if ($streamCopy) {
            $command = [...$command, '-c', 'copy'];
        } else {
            $command = [
                ...$command,
                ...$this->softwareTranscodeArguments->build(
                    copyVideo: $streams['video'] === 'h264',
                    hasAudio: $hasAudio,
                    targetSeconds: $previewTargetSeconds,
                ),
            ];
        }
        if ($streamCopy && $container === 'mp4') {
            $command = [...$command, '-movflags', '+faststart'];
        }
        $command[] = $outputPath;

        try {
            ($this->commandRunner)($command, $timeoutSeconds);
        } catch (Throwable $exception) {
            @unlink($outputPath);
            $this->logDeclinedEncode(
                $releaseGuid,
                $unsafeVideoCodec,
                $unsafeAudioCodec,
                ClipGenerationDeclineReason::RemuxFailed,
                [
                    'exception_message' => $exception->getMessage(),
                ],
            );

            return null;
        }

        if (! is_file($outputPath) || filesize($outputPath) === 0) {
            @unlink($outputPath);
            $this->logDeclinedEncode(
                $releaseGuid,
                $unsafeVideoCodec,
                $unsafeAudioCodec,
                ClipGenerationDeclineReason::EmptyOutput,
            );

            return null;
        }

        return new VideoClipEncodeResult(
            path: $outputPath,
            extension: $container,
            mime: self::CONTAINER_MIME_TYPES[$container],
            durationSeconds: $this->probeDurationSeconds($outputPath, $ffmpegBinary, $timeoutSeconds),
            bytes: (int) filesize($outputPath),
        );
    }

    /**
     * An unsafe codec is the causal decline when its fallback cannot produce a
     * Clip; otherwise report the mechanical failure of the stream-copy path.
     *
     * @param  array<string, int|string>  $context
     */
    private function logDeclinedEncode(
        string $releaseGuid,
        ?string $unsafeVideoCodec,
        ?string $unsafeAudioCodec,
        ClipGenerationDeclineReason $safeStreamReason,
        array $context = [],
    ): void {
        if ($unsafeVideoCodec !== null) {
            ClipGenerationLog::declined($releaseGuid, ClipGenerationDeclineReason::UnsafeVideoCodec, [
                'video_codec' => $unsafeVideoCodec,
                'failure_reason' => $safeStreamReason->value,
                ...$context,
            ]);

            return;
        }

        if ($unsafeAudioCodec !== null) {
            ClipGenerationLog::declined($releaseGuid, ClipGenerationDeclineReason::UnsafeAudioCodec, [
                'audio_codec' => $unsafeAudioCodec,
                'failure_reason' => $safeStreamReason->value,
                ...$context,
            ]);

            return;
        }

        ClipGenerationLog::declined($releaseGuid, $safeStreamReason, $context);
    }

    /**
     * The first video and audio stream codecs `ffmpeg -i` reports, or null
     * when the source has no recognizable video stream.
     *
     * @return array{video: string, audio: string|null}|null
     */
    private function probeStreams(string $sourcePath, string $ffmpegBinary, int $timeoutSeconds): ?array
    {
        try {
            $output = ($this->commandRunner)([
                $ffmpegBinary,
                '-hide_banner',
                '-nostdin',
                '-i',
                $sourcePath,
            ], $timeoutSeconds);
        } catch (Throwable) {
            return null;
        }

        if (preg_match('/Stream #\d+:\d+[^\r\n]*?: Video: ([a-z0-9]+)/i', $output, $video) !== 1) {
            return null;
        }

        $audio = null;
        if (preg_match('/Stream #\d+:\d+[^\r\n]*?: Audio: ([a-z0-9]+)/i', $output, $audioMatch) === 1) {
            $audio = strtolower($audioMatch[1]);
        }

        return ['video' => strtolower($video[1]), 'audio' => $audio];
    }

    private function probeDurationSeconds(string $path, string $ffmpegBinary, int $timeoutSeconds): ?int
    {
        try {
            $output = ($this->commandRunner)([
                $ffmpegBinary,
                '-hide_banner',
                '-nostdin',
                '-i',
                $path,
            ], $timeoutSeconds);
        } catch (Throwable) {
            return null;
        }

        if (preg_match('/Duration:\s*(\d{1,3}):(\d{2}):(\d{2}(?:\.\d+)?)/i', $output, $matches) !== 1) {
            return null;
        }

        return (int) round(((float) $matches[1] * 3600)
            + ((float) $matches[2] * 60)
            + (float) $matches[3]);
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, int $timeoutSeconds): string
    {
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds > 0 ? $timeoutSeconds : 60);
        $process->run();

        return $process->getOutput().$process->getErrorOutput();
    }
}
