<?php

declare(strict_types=1);

namespace App\Services\MediaInfo\Enums;

enum MediaInfoSourceCompleteness: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Unknown = 'unknown';
}
