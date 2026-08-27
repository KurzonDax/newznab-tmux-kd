<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\VideoHeadProbeResult;
use Closure;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Probes a downloaded video head for the container-declared duration and
 * overall bitrate, feeding the dynamic segment budget. Only header metadata
 * is read — for a bare non-faststart MP4 that means the probe is meaningful
 * only after a successful moov splice.
 */
class VideoHeadProbe
{
    /**
     * @var Closure(list<string>, int): string
     */
    private readonly Closure $commandRunner;

    /**
     * @param  (callable(list<string>, int): string)|null  $commandRunner
     */
    public function __construct(?callable $commandRunner = null)
    {
        $this->commandRunner = $commandRunner === null
            ? Closure::fromCallable([$this, 'runProcess'])
            : Closure::fromCallable($commandRunner);
    }

    public function probe(string $videoPath, ProcessingConfiguration $config): ?VideoHeadProbeResult
    {
        $ffmpegBinary = is_string($config->ffmpegPath) && $config->ffmpegPath !== ''
            ? $config->ffmpegPath
            : 'ffmpeg';

        try {
            $output = ($this->commandRunner)([
                $ffmpegBinary,
                '-hide_banner',
                '-nostdin',
                '-i',
                $videoPath,
            ], $config->timeoutSeconds > 0 ? $config->timeoutSeconds : 60);
        } catch (Throwable) {
            return null;
        }

        $duration = null;
        if (preg_match('/Duration:\s*(\d{1,3}):(\d{2}):(\d{2}(?:\.\d+)?)/i', $output, $matches) === 1) {
            $duration = ((float) $matches[1] * 3600)
                + ((float) $matches[2] * 60)
                + (float) $matches[3];
        }

        $bitsPerSecond = null;
        if (preg_match('/bitrate:\s*(\d+)\s*kb\/s/i', $output, $matches) === 1) {
            $bitsPerSecond = (int) $matches[1] * 1000;
        }

        if ($duration === null && $bitsPerSecond === null) {
            return null;
        }

        return new VideoHeadProbeResult($duration, $bitsPerSecond);
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
