<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Enums;

enum PayloadClassification: string
{
    case Rar = 'rar';
    case Zip = 'zip';
    case Par2 = 'par2';
    case Matroska = 'matroska';
    case Mp4 = 'mp4';
    case Avi = 'avi';
    case Text = 'text';
    case Unknown = 'unknown';

    public function mediaExtension(): ?string
    {
        return match ($this) {
            self::Matroska => 'mkv',
            self::Mp4 => 'mp4',
            self::Avi => 'avi',
            default => null,
        };
    }
}
