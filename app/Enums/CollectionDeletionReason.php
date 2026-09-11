<?php

declare(strict_types=1);

namespace App\Enums;

enum CollectionDeletionReason: string
{
    case Orphan = 'orphan';
    case Retention = 'retention';
    case MissedNzb = 'missed-nzb';
    case Stuck = 'stuck';
}
