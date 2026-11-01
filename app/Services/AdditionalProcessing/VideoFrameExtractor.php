<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use Closure;
use Symfony\Component\Process\Process;
use Throwable;

class VideoFrameExtractor
{
    /**
     * Luma standard deviation below which a decoded frame is considered flat
     * (near-black, near-white, or otherwise near-uniform) and rejected so the
     * next strategy runs. Real scenes sit well above this; fades, logos, and
     * lead-ins sit below it.
     */
    private const float FLAT_FRAME_LUMA_STDDEV = 8.0;

    /**
     * Frame widths tried for a moovless still rebuilt from x264 text, most common
     * first. The list covers 99.44% of the measured H.264 tracks.
     *
     * @var list<int>
     */
    private const array MOOVLESS_WIDTHS = [
        3840, 1920, 1280, 960, 720, 1440, 854, 4096, 640, 2560, 1080,
        704, 980, 2048, 2880, 768, 3200, 2160, 1024, 1756, 4320, 848,
    ];

    /** How many of the widths the two-pass pic_init_qp search repeats. */
    private const int MOOVLESS_QP_SEARCH_WIDTHS = 6;

    /** Macroblock rows written while the real count is unknown. */
    private const int MOOVLESS_PROBE_ROWS = 256;

    private const int MOOVLESS_MINIMUM_ROWS = 8;

    private const string DECODER_ERROR_PATTERN = '/error while decoding|out of range|cabac|mb_type|mb_skip|unavailable|negative|overread|bytestream|decode_slice|invalid|illegal|non-existing|missing/i';

    /**
     * @var Closure(list<string>, int): string
     */
    private readonly Closure $commandRunner;

    /**
     * @param  (callable(list<string>, int): string)|null  $commandRunner
     */
    public function __construct(
        private readonly ProcessingConfiguration $config,
        ?callable $commandRunner = null,
    ) {
        $this->commandRunner = $commandRunner === null
            ? Closure::fromCallable([$this, 'runProcess'])
            : Closure::fromCallable($commandRunner);
    }

    public function probeDecodableDuration(string $videoPath): ?float
    {
        $result = ($this->commandRunner)([
            $this->ffmpegBinary(),
            '-hide_banner',
            '-nostdin',
            '-v',
            'info',
            '-i',
            $videoPath,
            '-map',
            '0:v:0',
            '-an',
            '-f',
            'null',
            '-',
        ], $this->timeoutSeconds());

        if (preg_match_all('/time=\s*(\d{1,2}):(\d{2}):(\d{2}(?:\.\d+)?)/i', $result, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        $lastMatch = $matches[array_key_last($matches)];

        return ((float) $lastMatch[1] * 3600)
            + ((float) $lastMatch[2] * 60)
            + (float) $lastMatch[3];
    }

    public function representativeTimestamp(?float $decodableDuration): float
    {
        if ($decodableDuration === null || $decodableDuration <= 0) {
            return 0.0;
        }

        return floor($decodableDuration * 0.85 * 1000) / 1000;
    }

    public function extractRepresentativeFrame(string $videoPath, string $framePath): bool
    {
        try {
            $duration = $this->probeDecodableDuration($videoPath);
        } catch (Throwable) {
            $duration = null;
        }

        foreach ($this->frameCommands($videoPath, $framePath, $duration) as $command) {
            if (is_file($framePath)) {
                @unlink($framePath);
            }
            try {
                ($this->commandRunner)($command, $this->timeoutSeconds());
            } catch (Throwable) {
                continue;
            }

            if ($this->isUsableFrame($framePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decode a still from an H.264 MP4 head that has no reachable moov. Kept out
     * of {@see extractRepresentativeFrame()} because that method also checks clips.
     */
    public function extractMoovlessFrame(string $videoPath, string $framePath): bool
    {
        $streamPath = $framePath.'.h264';

        try {
            $head = is_file($videoPath) ? file_get_contents($videoPath, false, null, 0, MoovlessH264Stream::HEAD_BYTES) : false;
            $stream = $head === false ? null : MoovlessH264Stream::fromHead($head);
            if ($stream === null) {
                return false;
            }

            $settings = $stream->usesEncoderText()
                ? $this->searchForRebuiltSettings($stream, $streamPath)
                : $stream->inBandSettings();
            if ($settings === null) {
                return false;
            }

            foreach (array_reverse($stream->keyframeGroups()) as $group) {
                file_put_contents($streamPath, $settings.$group);
                $output = $this->runMoovlessFrameCommand($streamPath, $framePath, []);
                if (is_file($framePath)
                    && ! $this->hasDecoderError($output, [$streamPath, $framePath])
                    && ! str_contains($output, 'concealing')
                    && $this->isUsableFrame($framePath)
                ) {
                    return true;
                }
            }

            file_put_contents($streamPath, ($stream->usesEncoderText() ? $settings : '').$stream->sliceStream());
            $this->runMoovlessFrameCommand($streamPath, $framePath, ['-xerror']);

            return $this->isUsableFrame($framePath);
        } finally {
            if (is_file($streamPath)) {
                @unlink($streamPath);
            }
        }
    }

    /**
     * x264's text has no frame size, but ffmpeg's count of missing macroblocks
     * gives it: a width fits when the first keyframe decodes cleanly into whole
     * rows, and that many rows is the real height.
     */
    private function searchForRebuiltSettings(MoovlessH264Stream $stream, string $streamPath): ?string
    {
        $picInitQp = $stream->picInitQp();
        $fit = $this->widestFit($stream, $streamPath, self::MOOVLESS_WIDTHS, $picInitQp);

        if ($fit === null && $stream->usesTwoPassRateControl()) {
            $widths = array_slice(self::MOOVLESS_WIDTHS, 0, self::MOOVLESS_QP_SEARCH_WIDTHS);
            for ($picInitQp = 10; $picInitQp <= 40; $picInitQp++) {
                $fit = $this->widestFit($stream, $streamPath, $widths, $picInitQp);
                if ($fit !== null) {
                    break;
                }
            }
        }

        return $fit === null ? null : $stream->rebuiltSettings($fit['width'], $fit['rows'], $picInitQp);
    }

    /**
     * @param  list<int>  $widths
     * @return array{width: int, rows: int}|null
     */
    private function widestFit(MoovlessH264Stream $stream, string $streamPath, array $widths, int $picInitQp): ?array
    {
        $firstGroup = $stream->keyframeGroups()[0];
        $widest = null;

        foreach ($widths as $width) {
            file_put_contents($streamPath, $stream->rebuiltSettings($width, self::MOOVLESS_PROBE_ROWS, $picInitQp).$firstGroup);
            $output = ($this->commandRunner)([
                $this->ffmpegBinary(),
                '-hide_banner',
                '-nostdin',
                '-v',
                'info',
                '-f',
                'h264',
                '-i',
                $streamPath,
                '-frames:v',
                '1',
                '-f',
                'null',
                '-',
            ], $this->timeoutSeconds());

            $rows = $this->rowsFromProbe($output, $width, $streamPath);
            if ($rows !== null && ($widest === null || $width > $widest['width'])) {
                $widest = ['width' => $width, 'rows' => $rows];
            }
        }

        return $widest;
    }

    private function rowsFromProbe(string $output, int $width, string $streamPath): ?int
    {
        if ($this->hasDecoderError($output, [$streamPath])
            || preg_match('/concealing (\d+) DC/', $output, $matches) !== 1
        ) {
            return null;
        }

        $macroblockWidth = intdiv($width + 15, 16);
        $decoded = ($macroblockWidth * self::MOOVLESS_PROBE_ROWS) - (int) $matches[1];
        if ($decoded <= 0 || $decoded % $macroblockWidth !== 0) {
            return null;
        }

        $rows = intdiv($decoded, $macroblockWidth);

        return $rows >= self::MOOVLESS_MINIMUM_ROWS ? $rows : null;
    }

    /**
     * A path in ffmpeg's Input and Output lines is not a decoder error, and a
     * release guid in it can spell one of the words.
     *
     * @param  list<string>  $paths
     */
    private function hasDecoderError(string $output, array $paths): bool
    {
        return preg_match(self::DECODER_ERROR_PATTERN, str_replace($paths, '', $output)) === 1;
    }

    /**
     * @param  list<string>  $inputOptions
     */
    private function runMoovlessFrameCommand(string $streamPath, string $framePath, array $inputOptions): string
    {
        if (is_file($framePath)) {
            @unlink($framePath);
        }

        return ($this->commandRunner)([
            $this->ffmpegBinary(),
            '-hide_banner',
            '-nostdin',
            '-v',
            'info',
            '-y',
            ...$inputOptions,
            '-f',
            'h264',
            '-i',
            $streamPath,
            '-frames:v',
            '300',
            '-fps_mode',
            'passthrough',
            '-q:v',
            '2',
            '-update',
            '1',
            $framePath,
        ], $this->timeoutSeconds());
    }

    /**
     * @return list<list<string>>
     */
    private function frameCommands(string $videoPath, string $framePath, ?float $duration): array
    {
        $base = [
            $this->ffmpegBinary(),
            '-y',
            '-hide_banner',
            '-nostdin',
            '-loglevel',
            'error',
            '-i',
            $videoPath,
        ];

        // Near-end seek first: the last fully decodable frame of the fetched
        // window is past the logos and fade-ins that scene/thumbnail select.
        $commands = [];
        $nearEnd = $this->representativeTimestamp($duration);
        if ($nearEnd > 0) {
            $commands[] = [...$base, '-ss', number_format($nearEnd, 3, '.', ''), '-frames:v', '1', '-q:v', '2', $framePath];
        }

        $commands[] = [...$base, '-vf', 'select=gt(scene\,0.30)', '-frames:v', '1', '-q:v', '2', $framePath];
        $commands[] = [...$base, '-vf', 'thumbnail=30', '-frames:v', '1', '-q:v', '2', $framePath];
        $commands[] = [...$base, '-ss', '0.000', '-frames:v', '1', '-q:v', '2', $framePath];

        return $commands;
    }

    private function ffmpegBinary(): string
    {
        return is_string($this->config->ffmpegPath) && $this->config->ffmpegPath !== ''
            ? $this->config->ffmpegPath
            : 'ffmpeg';
    }

    private function timeoutSeconds(): int
    {
        return $this->config->timeoutSeconds > 0 ? $this->config->timeoutSeconds : 60;
    }

    /**
     * A frame is usable when it decodes as a JPEG and is not flat: a frame
     * that decodes but is near-black, near-white, or otherwise near-uniform
     * is rejected so the next strategy runs.
     */
    private function isUsableFrame(string $framePath): bool
    {
        if (! is_file($framePath) || filesize($framePath) < 4) {
            return false;
        }

        $header = file_get_contents($framePath, false, null, 0, 2);
        if ($header !== "\xFF\xD8") {
            return false;
        }

        set_error_handler(static fn (): bool => true);
        try {
            $image = imagecreatefromjpeg($framePath);
        } finally {
            restore_error_handler();
        }

        if ($image === false) {
            return false;
        }

        return ! $this->isFlatImage($image);
    }

    private function isFlatImage(\GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // Sample at most a 64x64 grid so large frames stay cheap.
        $stepX = max(1, intdiv($width, 64));
        $stepY = max(1, intdiv($height, 64));
        $sum = 0.0;
        $sumSquares = 0.0;
        $count = 0;

        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                $rgb = imagecolorat($image, $x, $y);
                $luma = 0.299 * (($rgb >> 16) & 0xFF)
                    + 0.587 * (($rgb >> 8) & 0xFF)
                    + 0.114 * ($rgb & 0xFF);
                $sum += $luma;
                $sumSquares += $luma * $luma;
                $count++;
            }
        }

        $mean = $sum / $count;
        $variance = max(($sumSquares / $count) - ($mean * $mean), 0.0);

        return sqrt($variance) < self::FLAT_FRAME_LUMA_STDDEV;
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, int $timeoutSeconds): string
    {
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);
        try {
            $process->run();
        } catch (Throwable) {
            return $process->getOutput().$process->getErrorOutput();
        }

        return $process->getOutput().$process->getErrorOutput();
    }
}
