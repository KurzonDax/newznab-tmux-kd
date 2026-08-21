<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\Enums;

/**
 * Where the preview clip's audio has to come from.
 */
enum AudioSourceKind: string
{
    /** A posted audio file, fetchable head-first. */
    case BareFile = 'bare-file';

    /** A RAR/7z/zip set that has to be fetched part by part and opened. */
    case Archive = 'archive';
}
