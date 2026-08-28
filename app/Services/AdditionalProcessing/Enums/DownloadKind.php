<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Enums;

enum DownloadKind: string
{
    case Sample = 'sample';
    case MediaInfo = 'media-info';
    case MediaInfoTail = 'media-info-tail';
    case MediaInfoTopUp = 'media-info-top-up';
    case Audio = 'audio';
    case Jpg = 'jpg';
    case Compressed = 'compressed';
    case CompressedTopUp = 'compressed-top-up';
    case PayloadSniff = 'payload-sniff';
}
