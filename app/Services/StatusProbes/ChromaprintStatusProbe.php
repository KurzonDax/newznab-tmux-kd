<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Services\AudioProcessing\ChromaprintCapabilityProbe;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;

/**
 * Reports whether audio processing can fingerprint tracks with Chromaprint.
 *
 * A missing capability degrades music identification but stops nothing else,
 * so it is a minor impact rather than an outage.
 */
final class ChromaprintStatusProbe implements ServiceProbeInterface
{
    public function __construct(
        private readonly ChromaprintCapabilityProbe $capabilityProbe,
    ) {}

    public function identifier(): string
    {
        return 'chromaprint';
    }

    public function probe(): ProbeResult
    {
        $start = hrtime(true);
        $capability = $this->capabilityProbe->probe();
        $elapsed = (int) ((hrtime(true) - $start) / 1_000_000);

        return new ProbeResult(
            ok: $capability->available,
            responseTimeMs: $elapsed,
            impact: $capability->available ? null : IncidentImpactEnum::Minor,
            reason: $capability->reason,
            metadata: ['available' => $capability->available, 'ffmpegVersion' => $capability->ffmpegVersion],
        );
    }
}
