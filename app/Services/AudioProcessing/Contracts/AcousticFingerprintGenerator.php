<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\Contracts;

use App\Services\AudioProcessing\DTO\AcousticFingerprint;

/**
 * Derives an acoustic fingerprint from local, beginning-anchored source audio.
 *
 * Callers hand over the fetched source before temporary cleanup, never the
 * served preview. A null result means no trustworthy fingerprint exists for
 * this file: the capability is missing, or generation failed.
 */
interface AcousticFingerprintGenerator
{
    public function generate(string $sourcePath): ?AcousticFingerprint;
}
