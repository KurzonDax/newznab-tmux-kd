<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A fingerprint lookup that produced no answer. A retryable failure (transport, throttling,
 * service error, unreadable response) defers the whole decision; any other failure rejects only
 * this fingerprint.
 */
final class AcousticFingerprintLookupException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        ?Throwable $previous = null,
        public readonly bool $cacheable = false,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
