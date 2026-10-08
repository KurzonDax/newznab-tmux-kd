<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Contracts;

use App\Services\MusicIdentity\DTO\AcousticFingerprintMatch;
use App\Services\MusicIdentity\DTO\AcousticFingerprintQuery;
use App\Services\MusicIdentity\Exceptions\AcousticFingerprintLookupException;

/**
 * Maps one acoustic fingerprint to the MusicBrainz recordings a fingerprint service links to it.
 * Lookup only: nothing is ever submitted to the service.
 */
interface AcousticFingerprintMatcher
{
    /** Whether lookups can run at all; false keeps the matcher dormant. */
    public function available(): bool;

    /**
     * An empty list is a genuine no-match; a failed lookup throws instead.
     *
     * @return list<AcousticFingerprintMatch>
     *
     * @throws AcousticFingerprintLookupException
     */
    public function lookup(AcousticFingerprintQuery $query): array;
}
