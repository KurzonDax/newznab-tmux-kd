<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Enums\ObfuscationRecoveryProfile;

enum RecoveryAlgorithm: string
{
    case Media = 'nyuu-media-v1';
    case Rar = 'nyuu-rar-sequential-v1';

    public function selection(): ObfuscationRecoveryProfile
    {
        return $this === self::Media ? ObfuscationRecoveryProfile::Media : ObfuscationRecoveryProfile::Rar;
    }

    public function payloadRole(): RecoveryFileRole
    {
        return $this === self::Media ? RecoveryFileRole::Media : RecoveryFileRole::RarVolume;
    }
}
