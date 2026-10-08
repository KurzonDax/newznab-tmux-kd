<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

/**
 * One fingerprint-service track (a cluster of submitted fingerprints) and the MusicBrainz
 * recordings linked to it, which may be none. The score is the service's own ranking value,
 * a provider feature and never a calibrated probability.
 */
final readonly class AcousticFingerprintMatch
{
    /** @param list<AcousticRecording> $recordings */
    public function __construct(
        public string $trackId,
        public float $score,
        public array $recordings,
    ) {}
}
