<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Services\AdditionalProcessing\MediaTools;
use App\Services\AudioProcessing\DTO\AudioPreviewResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Cuts the preview clip, and renders the spectrogram beside it.
 *
 * The clip is a stream copy wherever the browser can play the source codec, so
 * what a listener hears is the posted audio itself -- the point of a music
 * preview is judging the encode, and the old path's Vorbis re-encode destroyed
 * exactly the information needed to do that. Sources no browser plays are
 * re-encoded to FLAC, which is still lossless, so a preview never loses a
 * generation.
 */
final class AudioPreviewEncoder
{
    /**
     * Source codecs a browser plays, mapped to the container the copy is written
     * into. Anything absent here is transcoded.
     *
     * @var array<string, string>
     */
    private const array COPYABLE_CODEC_CONTAINERS = [
        'mp3' => 'mp3',
        'aac' => 'm4a',
        'vorbis' => 'ogg',
        'opus' => 'opus',
        'flac' => 'flac',
        'pcm_s16le' => 'wav',
        'pcm_s24le' => 'wav',
        'pcm_s32le' => 'wav',
        'pcm_u8' => 'wav',
        'pcm_f32le' => 'wav',
    ];

    /** @var array<string, string> */
    private const array CONTAINER_MIME_TYPES = [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/opus',
        'flac' => 'audio/flac',
        'wav' => 'audio/wav',
    ];

    private const string TRANSCODE_CONTAINER = 'flac';

    public function __construct(
        private readonly AudioProcessingConfiguration $config,
        private readonly MediaTools $mediaTools,
    ) {}

    /**
     * Encode the clip for one release and move it into the covers tree.
     *
     * @param  string  $sourcePath  The fetched audio, however much of it there is.
     */
    public function encode(string $sourcePath, string $guid, string $tmpPath): ?AudioPreviewResult
    {
        if (! File::isFile($sourcePath)) {
            return null;
        }

        $codec = $this->sourceCodec($sourcePath);
        $container = self::COPYABLE_CODEC_CONTAINERS[$codec] ?? self::TRANSCODE_CONTAINER;
        $streamCopied = array_key_exists($codec, self::COPYABLE_CODEC_CONTAINERS);

        $workingPath = $tmpPath.$guid.'.'.$container;
        $seconds = $this->cut($sourcePath, $workingPath, $container, $streamCopied);

        if ($seconds === null) {
            File::delete($workingPath);

            return null;
        }

        $bytes = (int) File::size($workingPath);

        if (! $this->store($workingPath, $this->config->savePath.$guid.'.'.$container)) {
            return null;
        }

        return new AudioPreviewResult(
            extension: $container,
            mimeType: self::CONTAINER_MIME_TYPES[$container],
            seconds: $seconds,
            bytes: $bytes,
            streamCopied: $streamCopied,
        );
    }

    /**
     * Render the spectrogram over the whole fetched span, not just the clip: it
     * is there to show where the encoder's low-pass sits, and more audio makes
     * that easier to see, not harder.
     */
    public function renderSpectrogram(string $sourcePath, string $guid, string $tmpPath): bool
    {
        if (! $this->config->spectrogram || ! File::isFile($sourcePath)) {
            return false;
        }

        $workingPath = $tmpPath.$guid.'_spectrum.png';

        $rendered = $this->run([
            '-y',
            '-i', $sourcePath,
            '-lavfi', 'showspectrumpic=s=1024x256:legend=1:color=intensity',
            $workingPath,
        ]) && $this->isNonEmpty($workingPath);

        if (! $rendered) {
            File::delete($workingPath);

            return false;
        }

        return $this->store($workingPath, $this->config->savePath.$guid.'_spectrum.png');
    }

    /**
     * Cut the clip, and say how long it came out.
     *
     * The window is decided from the source's own duration before ffmpeg runs,
     * rather than by trying an offset and inspecting the result: a stream copy
     * carries the source's STREAMINFO into the output, so a FLAC clip taken from
     * ten seconds into a twenty second track still reports twenty. Deciding up
     * front also means one ffmpeg invocation instead of two.
     *
     * A source shorter than the offset is clipped from the very start; a source
     * shorter than the window yields a shorter clip. Neither is a failure.
     *
     * @return int|null The clip's length in whole seconds, or null if ffmpeg
     *                  produced nothing.
     */
    private function cut(string $sourcePath, string $outputPath, string $container, bool $streamCopy): ?int
    {
        $sourceSeconds = $this->probeDuration($sourcePath);
        $offset = $this->config->previewStartSeconds;
        $length = $this->config->previewSeconds;

        if ($sourceSeconds > 0.0) {
            if ((float) $offset >= $sourceSeconds) {
                $offset = 0;
            }

            $length = max(1, (int) min($length, (int) round($sourceSeconds - $offset)));
        }

        File::delete($outputPath);

        $command = [
            '-y',
            '-ss', (string) $offset,
            '-t', (string) $length,
            '-i', $sourcePath,
            // Strip tags and embedded cover art: the row carries the metadata,
            // and an attached picture would be dead weight on every request.
            '-map_metadata', '-1',
            '-vn',
        ];

        $command = $streamCopy
            ? [...$command, '-c:a', 'copy']
            : [...$command, '-c:a', $container, '-compression_level', '5'];

        $command[] = $outputPath;

        if (! $this->run($command) || ! $this->isNonEmpty($outputPath)) {
            return null;
        }

        return $length;
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): bool
    {
        try {
            $this->mediaTools->ffmpeg()->getFFMpegDriver()->command($command);

            return true;
        } catch (\Throwable $e) {
            if ($this->config->debugMode) {
                Log::debug('ffmpeg audio command failed: '.$e->getMessage());
            }

            return false;
        }
    }

    private function sourceCodec(string $path): string
    {
        try {
            $audio = $this->mediaTools->ffprobe()->streams($path)->audios()->first();

            return $audio === null ? '' : strtolower((string) $audio->get('codec_name'));
        } catch (\Throwable $e) {
            if ($this->config->debugMode) {
                Log::debug('ffprobe could not read the audio codec: '.$e->getMessage());
            }

            return '';
        }
    }

    /**
     * How much audio the source declares, or 0.0 when ffprobe cannot say.
     *
     * For a head-fetched file this is the whole posted track's length, not the
     * bytes on disk, so the clip can come out shorter than the window this
     * yields. That is the closest reading available without decoding, and a
     * preview that is short is still a preview.
     */
    private function probeDuration(string $path): float
    {
        try {
            return max(0.0, (float) $this->mediaTools->ffprobe()->format($path)->get('duration'));
        } catch (\Throwable $e) {
            if ($this->config->debugMode) {
                Log::debug('ffprobe could not read the source duration: '.$e->getMessage());
            }

            return 0.0;
        }
    }

    private function isNonEmpty(string $path): bool
    {
        return File::isFile($path) && (int) File::size($path) > 0;
    }

    private function store(string $from, string $to): bool
    {
        try {
            File::ensureDirectoryExists(dirname($to), 0777);

            return File::move($from, $to);
        } catch (\Throwable $e) {
            Log::error('Could not store the audio preview artifact: '.$e->getMessage(), ['path' => $to]);

            return false;
        }
    }
}
