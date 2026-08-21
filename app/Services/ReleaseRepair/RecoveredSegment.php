<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

/**
 * One article the header re-scan found for a file the NZB does not hold.
 *
 * Unlike a segment the repair engine synthesizes, both the message-ID and the byte count are
 * read off the server's own overview line, so neither is an estimate.
 */
final readonly class RecoveredSegment
{
    public function __construct(
        public string $messageId,
        public int $bytes,
    ) {}
}
