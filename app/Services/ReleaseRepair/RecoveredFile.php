<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

/**
 * A file the header scan missed entirely, rebuilt from overview lines.
 *
 * A file may be recovered only partly -- the re-scan window can hold some of its articles and
 * not others. It is written with what was found; the repair engine's next pass synthesizes the
 * rest from the message-IDs this one supplies, which is the whole point of writing a partial
 * file rather than discarding it.
 */
final readonly class RecoveredFile
{
    /**
     * @param  array<int, RecoveredSegment>  $segments  Segment number => segment.
     */
    public function __construct(
        public int $fileIndex,
        public string $subject,
        public array $segments,
    ) {}
}
