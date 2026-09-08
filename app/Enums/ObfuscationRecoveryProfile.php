<?php

declare(strict_types=1);

namespace App\Enums;

enum ObfuscationRecoveryProfile: string
{
    case Disabled = 'disabled';
    case Media = 'media';
    case Rar = 'rar';
    case Both = 'both';

    public function permits(self $profile): bool
    {
        return in_array($profile, [self::Media, self::Rar], true)
            && ($this === self::Both || $this === $profile);
    }

    public function label(): string
    {
        return match ($this) {
            self::Disabled => 'Disabled',
            self::Media => 'Media files',
            self::Rar => 'Multi-volume RAR',
            self::Both => 'Both',
        };
    }
}
