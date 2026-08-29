<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use Mhor\MediaInfo\MediaInfo;
use Symfony\Component\Process\Process;

/**
 * Lazily built handles on the external media binaries.
 *
 * Both post-processing paths shell out to the same media tools with the same
 * configuration; sharing one holder keeps a single place where their timeouts
 * and binary paths are decided, and keeps the processes from being spawned for
 * a release that never reaches them.
 */
final class MediaTools
{
    private ?FFMpeg $ffmpeg = null;

    private ?FFProbe $ffprobe = null;

    private ?MediaInfo $mediaInfo = null;

    private string|false|null $wvunpackPath = null;

    public function __construct(
        private readonly int $timeoutSeconds = 0,
        private readonly string|false $mediaInfoPath = false,
    ) {}

    public function ffmpeg(): FFMpeg
    {
        return $this->ffmpeg ??= FFMpeg::create([
            'timeout' => $this->timeoutSeconds > 0 ? $this->timeoutSeconds : 60,
        ]);
    }

    public function ffprobe(): FFProbe
    {
        return $this->ffprobe ??= FFProbe::create();
    }

    public function mediaInfo(): MediaInfo
    {
        if ($this->mediaInfo === null) {
            $this->mediaInfo = new MediaInfo;
            $this->mediaInfo->setConfig('use_oldxml_mediainfo_output_format', true);
            if ($this->mediaInfoPath !== false) {
                $this->mediaInfo->setConfig('command', $this->mediaInfoPath);
            }
        }

        return $this->mediaInfo;
    }

    public function wvunpackPath(): string|false
    {
        if ($this->wvunpackPath === null) {
            $process = new Process(['sh', '-c', 'command -v wvunpack']);
            $process->setTimeout($this->timeoutSeconds > 0 ? $this->timeoutSeconds : 60);
            $process->run();

            $path = trim($process->getOutput());
            $this->wvunpackPath = $process->isSuccessful() && $path !== '' && is_executable($path)
                ? $path
                : false;
        }

        return $this->wvunpackPath;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds > 0 ? $this->timeoutSeconds : 60;
    }
}
