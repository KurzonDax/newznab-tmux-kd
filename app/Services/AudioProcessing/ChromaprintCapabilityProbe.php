<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Services\AudioProcessing\DTO\ChromaprintCapability;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Asks the configured FFmpeg whether it was built with the Chromaprint muxer.
 *
 * Not every FFmpeg package enables Chromaprint, so fingerprinting is never
 * assumed. An audio worker probes once when it starts a non-empty batch and
 * logs a missing capability once; the status page probes afresh on every
 * check.
 */
final class ChromaprintCapabilityProbe
{
    private ?ChromaprintCapability $capability = null;

    /**
     * @param  string  $ffmpegBinary  A path or a bare name looked up on PATH.
     */
    public function __construct(
        private readonly string $ffmpegBinary,
        private readonly int $timeoutSeconds,
    ) {}

    public function binary(): string
    {
        return $this->ffmpegBinary;
    }

    /**
     * The capability for the life of this worker process, probed on first use.
     */
    public function capability(): ChromaprintCapability
    {
        if ($this->capability === null) {
            $this->capability = $this->probe();
            if (! $this->capability->available) {
                Log::info('Acoustic fingerprints are skipped: '.$this->capability->reason);
            }
        }

        return $this->capability;
    }

    public function probe(): ChromaprintCapability
    {
        try {
            // Without -hide_banner the version line goes to stderr and the
            // muxer list to stdout, so one run answers both questions.
            $result = Process::timeout(max(1, $this->timeoutSeconds))
                ->run([$this->ffmpegBinary, '-nostdin', '-muxers']);
        } catch (\Throwable $exception) {
            return ChromaprintCapability::unavailable(
                'FFmpeg ('.$this->ffmpegBinary.') could not be run: '.Str::limit($exception->getMessage(), 120),
            );
        }

        $version = preg_match('/ffmpeg version (\S+)/i', $result->errorOutput().$result->output(), $match) === 1
            ? Str::limit($match[1], 64, '')
            : null;

        if (! $result->successful()) {
            return ChromaprintCapability::unavailable(
                'FFmpeg ('.$this->ffmpegBinary.') exited with status '.$result->exitCode().' while listing its muxers.',
                $version,
            );
        }

        if (preg_match('/^\s*D?E\s+chromaprint\s/mi', $result->output()) !== 1) {
            return ChromaprintCapability::unavailable(
                'FFmpeg '.($version ?? '(unknown version)').' was built without the Chromaprint muxer.',
                $version,
            );
        }

        return ChromaprintCapability::available($version);
    }
}
