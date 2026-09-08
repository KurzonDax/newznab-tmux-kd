<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

enum RecoveryStage: string
{
    case Discover = 'discover';
    case Download = 'download';
    case Publish = 'publish';
}
