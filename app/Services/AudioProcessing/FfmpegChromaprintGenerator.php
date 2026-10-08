<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing;

use App\Services\AudioProcessing\Contracts\AcousticFingerprintGenerator;
use App\Services\AudioProcessing\DTO\AcousticFingerprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Fingerprints local audio with FFmpeg's Chromaprint muxer.
 *
 * Analysis starts at time zero and stops after the first 120 decoded
 * seconds, the window fpcalc uses by default. Only the compressed base64
 * fingerprint leaves the process; no PCM is written anywhere. A non-zero exit
 * discards the output even when a fingerprint-shaped line was printed.
 */
final class FfmpegChromaprintGenerator implements AcousticFingerprintGenerator
{
    public const int WINDOW_SECONDS = 120;

    /** Chromaprint's public algorithm number (fpcalc `-algorithm 2`). */
    public const int ALGORITHM = 2;

    private const string GENERATOR = 'ffmpeg-chromaprint-120s-v1';

    public function __construct(
        private readonly ChromaprintCapabilityProbe $capabilityProbe,
        private readonly int $timeoutSeconds,
    ) {}

    public function generate(string $sourcePath): ?AcousticFingerprint
    {
        if (! is_file($sourcePath)) {
            return null;
        }

        $capability = $this->capabilityProbe->capability();
        if (! $capability->available) {
            return null;
        }

        try {
            $result = Process::timeout(max(1, $this->timeoutSeconds))->run([
                $this->capabilityProbe->binary(),
                '-hide_banner',
                '-nostdin',
                '-v',
                'error',
                '-i',
                $sourcePath,
                '-map',
                '0:a:0',
                '-t',
                (string) self::WINDOW_SECONDS,
                // The muxer accepts at most two channels; mono and stereo pass
                // through unchanged and anything wider is downmixed to stereo.
                '-af',
                'aformat=channel_layouts=mono|stereo',
                '-f',
                'chromaprint',
                // FFmpeg passes Chromaprint's zero-based enum through: 1 is
                // the library's default algorithm 2.
                '-algorithm',
                (string) (self::ALGORITHM - 1),
                '-fp_format',
                'base64',
                '-',
            ]);
        } catch (\Throwable $exception) {
            Log::debug('Chromaprint fingerprinting could not run: '.Str::limit($exception->getMessage(), 200));

            return null;
        }

        if (! $result->successful()) {
            Log::debug(sprintf(
                'Chromaprint fingerprinting exited with status %d: %s',
                (int) $result->exitCode(),
                Str::limit(trim($result->errorOutput()), 200),
            ));

            return null;
        }

        $fingerprint = trim($result->output());
        if (! $this->isCompressedFingerprint($fingerprint)) {
            Log::debug('Chromaprint fingerprinting produced no valid fingerprint.');

            return null;
        }

        return new AcousticFingerprint(
            $fingerprint,
            self::ALGORITHM,
            Str::limit(self::GENERATOR.' ffmpeg/'.($capability->ffmpegVersion ?? 'unknown'), 128, ''),
        );
    }

    /**
     * A compressed fingerprint is URL-safe base64 whose first decoded byte is
     * the algorithm, followed by a three-byte element count.
     */
    private function isCompressedFingerprint(string $fingerprint): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $fingerprint) !== 1) {
            return false;
        }

        $decoded = base64_decode(strtr($fingerprint, '-_', '+/'), true);

        return is_string($decoded)
            && strlen($decoded) >= 4
            && ord($decoded[0]) === self::ALGORITHM - 1;
    }
}
