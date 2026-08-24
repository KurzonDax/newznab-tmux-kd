<?php

declare(strict_types=1);

namespace App\Enums;

enum NzbCreationFailureDisposition: string
{
    case Deleted = 'deleted';

    case Retry = 'retry';

    case ClaimLost = 'claim-lost';
}
