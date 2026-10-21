<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

/** Whether a name source makes the release a trusted donor (`releases.is_trusted_name`). */
enum TrustedDonor
{
    case Trusted;
    case Untrusted;

    /** The rename leaves `is_trusted_name` and `predb_id` as they are. */
    case Keep;
}
