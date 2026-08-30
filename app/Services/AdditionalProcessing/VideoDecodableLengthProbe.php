<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Measures the playable span in a possibly truncated video extraction.
 *
 * Container duration and extracted byte count include metadata that may not
 * represent playable media. Packet timestamps tell the compressed preview
 * budget how much video ffprobe can actually demux from the current fragment.
 */
class VideoDecodableLengthProbe
{
    /** @var Closure(list<string>, int): string */
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

    public function demuxedSeconds(string $path, ProcessingConfiguration $config): ?float
    {
        try {
            $output = ($this->commandRunner)([
                $this->ffprobeBinary($config),
                '-v',
                'error',
                '-select_streams',
                'v:0',
                '-show_entries',
                'packet=pts_time,duration_time',
                '-of',
                'csv=p=0',
                $path,
            ], $config->timeoutSeconds > 0 ? $config->timeoutSeconds : 60);
        } catch (Throwable) {
            return null;
        }

        $firstTimestamp = null;
        $lastPacketEnd = null;

        foreach (preg_split('/\R/', trim($output)) ?: [] as $packet) {
            $fields = str_getcsv(trim($packet), ',', '"', '');
            if (! isset($fields[0]) || ! is_numeric($fields[0])) {
                continue;
            }

            $timestamp = (float) $fields[0];
            $duration = isset($fields[1]) && is_numeric($fields[1]) ? (float) $fields[1] : 0.0;
            $firstTimestamp = $firstTimestamp === null ? $timestamp : min($firstTimestamp, $timestamp);
            $lastPacketEnd = max($lastPacketEnd ?? $timestamp, $timestamp + max($duration, 0.0));
        }

        if ($firstTimestamp === null || $lastPacketEnd === null) {
            return null;
        }

        return max(0.0, $lastPacketEnd - $firstTimestamp);
    }

    private function ffprobeBinary(ProcessingConfiguration $config): string
    {
        $ffmpegBinary = is_string($config->ffmpegPath) && $config->ffmpegPath !== ''
            ? $config->ffmpegPath
            : 'ffmpeg';

        return preg_replace('/ffmpeg$/i', 'ffprobe', $ffmpegBinary) ?? 'ffprobe';
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, int $timeoutSeconds): string
    {
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful() && trim($process->getOutput()) === '') {
            throw new RuntimeException($process->getErrorOutput());
        }

        return $process->getOutput();
    }
}
