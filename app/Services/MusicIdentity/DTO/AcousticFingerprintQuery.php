<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

/**
 * One stored fingerprint and the whole-file duration it is looked up with. The algorithm,
 * generator version, hash and duration together identify a cached lookup result.
 */
final readonly class AcousticFingerprintQuery
{
    public function __construct(
        public string $fingerprint,
        public string $fingerprintHash,
        public int $algorithm,
        public string $generatorVersion,
        public int $durationSeconds,
    ) {}
}
