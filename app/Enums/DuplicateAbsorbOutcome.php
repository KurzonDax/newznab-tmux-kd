<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a duplicate-absorb call ended, so call sites can tell the four states
 * apart: absorbed (anchor upgraded in place), not-better (ordinary duplicate,
 * discard), deferred (the anchor has no stored NZB yet — preserve silently and
 * retry a later cycle), and failed (attempted and could not complete —
 * preserve and count, or settle at the attempt cap).
 */
enum DuplicateAbsorbOutcome
{
    case Absorbed;

    case NotBetter;

    case Deferred;

    case Failed;
}
