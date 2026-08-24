<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Enums;

enum NzbParseFailure: string
{
    case StorageUnavailable = 'storage-unavailable';
    case Missing = 'missing';
    case Broken = 'broken';
}
