<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Enums;

enum DownloadKind: string
{
    case Sample = 'sample';
    case MediaInfo = 'media-info';
    case MediaInfoTail = 'media-info-tail';
    case Audio = 'audio';
    case Jpg = 'jpg';
    case Compressed = 'compressed';
    case PayloadSniff = 'payload-sniff';
}
