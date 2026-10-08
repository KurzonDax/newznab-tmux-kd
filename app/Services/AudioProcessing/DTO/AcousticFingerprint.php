<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\DTO;

/**
 * One compressed, base64-encoded Chromaprint fingerprint and its provenance.
 *
 * The algorithm uses Chromaprint's public numbering (fpcalc `-algorithm`),
 * so the default algorithm AcoustID expects is 2. Durations and completeness
 * stay on the evidence track, where the fetcher established them
 * independently of the fingerprint process.
 */
final readonly class AcousticFingerprint
{
    public string $hash;

    public function __construct(
        public string $fingerprint,
        public int $algorithm,
        public string $generatorVersion,
    ) {
        $this->hash = hash('sha256', $fingerprint);
    }

    /**
     * @return array{fingerprint: string, fingerprint_hash: string, fingerprint_algorithm: int, fingerprint_generator_version: string}
     */
    public function toTrackFacts(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'fingerprint_hash' => $this->hash,
            'fingerprint_algorithm' => $this->algorithm,
            'fingerprint_generator_version' => $this->generatorVersion,
        ];
    }
}
