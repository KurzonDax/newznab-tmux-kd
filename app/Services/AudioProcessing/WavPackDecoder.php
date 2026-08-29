<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Services\AdditionalProcessing\MediaTools;
use App\Services\AudioProcessing\Exceptions\WavPackDecoderUnavailable;
use Symfony\Component\Process\Process;

/**
 * Decodes WavPack with its reference CLI when ffmpeg cannot demux the source.
 */
final class WavPackDecoder
{
    public function __construct(private readonly MediaTools $mediaTools) {}

    public function supports(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'wv';
    }

    public function available(): bool
    {
        return $this->mediaTools->wvunpackPath() !== false;
    }

    public function decode(string $sourcePath, string $outputPath): bool
    {
        $binary = $this->mediaTools->wvunpackPath();
        if ($binary === false) {
            throw new WavPackDecoderUnavailable;
        }

        if (is_file($outputPath)) {
            unlink($outputPath);
        }

        try {
            $process = new Process([
                $binary,
                '-b',
                '-w',
                '-y',
                '-q',
                $sourcePath,
                '-o',
                $outputPath,
            ]);
            $process->setTimeout($this->mediaTools->timeoutSeconds());
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        // A truncated source makes wvunpack report a non-zero exit status even
        // though the WAV it decoded before EOF is valid and useful.
        return is_file($outputPath) && filesize($outputPath) > 44;
    }
}
