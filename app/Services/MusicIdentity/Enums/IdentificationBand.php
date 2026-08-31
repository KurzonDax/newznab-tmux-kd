<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Enums;

enum IdentificationBand: string
{
    case Verified = 'verified';
    case Strong = 'strong';
    case Suggestive = 'suggestive';
    case Unresolved = 'unresolved';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 97 => self::Verified,
            $score >= 92 => self::Strong,
            $score >= 75 => self::Suggestive,
            default => self::Unresolved,
        };
    }
}
