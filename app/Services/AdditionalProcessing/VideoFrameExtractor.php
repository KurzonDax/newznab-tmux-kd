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
