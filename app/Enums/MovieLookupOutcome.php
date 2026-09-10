<?php

declare(strict_types=1);

namespace App\Enums;

enum MovieLookupOutcome
{
    case Matched;
    case Series;
    case NotFound;
    case Unavailable;
}
