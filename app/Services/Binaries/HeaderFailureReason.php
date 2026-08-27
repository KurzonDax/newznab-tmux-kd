<?php

declare(strict_types=1);

namespace App\Services\Binaries;

/**
 * Why a single parsed header could not be placed inside a storage chunk.
 *
 * The distinction drives both retry policy and reporting: unresolved ids are
 * usually a transient visibility race between concurrent group scans, while a
 * rejected part is a permanently unusable header.
 */
enum HeaderFailureReason
{
    /** The collection id could not be resolved after insert-or-ignore. */
    case UnresolvedCollection;

    /** The binary id could not be resolved after insert-or-ignore. */
    case UnresolvedBinary;

    /** The part itself was rejected (invalid message-id/part number) or its flush failed. */
    case RejectedPart;

    /**
     * Unresolved ids are retryable; a fresh transaction gets a fresh snapshot.
     */
    public function isTransientRace(): bool
    {
        return $this !== self::RejectedPart;
    }
}
