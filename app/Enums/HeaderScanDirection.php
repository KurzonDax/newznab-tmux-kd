<?php

declare(strict_types=1);

namespace App\Enums;

enum HeaderScanDirection
{
    case Head;
    case Tail;
    case Repair;
}
