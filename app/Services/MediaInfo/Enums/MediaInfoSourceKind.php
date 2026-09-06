<?php

declare(strict_types=1);

namespace App\Services\MediaInfo\Enums;

enum MediaInfoSourceKind: string
{
    case AdditionalProcessing = 'additional-processing';
    case AudioProcessing = 'audio-processing';
}
