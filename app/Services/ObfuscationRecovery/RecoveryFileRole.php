<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

enum RecoveryFileRole: string
{
    case Media = 'media';
    case RarVolume = 'rar_volume';
    case Index = 'index';
}
