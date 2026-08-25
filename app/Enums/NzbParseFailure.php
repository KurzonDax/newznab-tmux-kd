<?php

namespace App\Enums;

enum NzbParseFailure: string
{
    case StorageUnavailable = 'storage-unavailable';
    case Missing = 'missing';
    case Broken = 'broken';
}
