<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

enum RecoveryNameScope: string
{
    case SingleFile = 'single_file';
    case DescriptiveBundle = 'descriptive_bundle';
    case ArchiveSet = 'archive_set';
}
