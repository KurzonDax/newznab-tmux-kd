<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Enums;

enum AcceptedIdentityScope: string
{
    case Recording = 'recording';
    case ReleaseGroup = 'release_group';
    case Edition = 'edition';
}
