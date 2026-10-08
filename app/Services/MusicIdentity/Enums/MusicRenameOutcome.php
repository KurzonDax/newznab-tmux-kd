<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Enums;

/** What the canonical rename projection did with one accepted album decision (issue #309). */
enum MusicRenameOutcome: string
{
    /** The release carries the decision's canonical name; the record holds every field it changed. */
    case Applied = 'applied';

    /** The rename gate refused the decision; the record holds the reason. */
    case Declined = 'declined';

    /** The decision stopped being current and the fields still holding its values were restored. */
    case Reverted = 'reverted';
}
