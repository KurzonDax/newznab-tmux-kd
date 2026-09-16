<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Enums;

enum PasswordVerdict: string
{
    case VerifiedEncrypted = 'verified-encrypted';
    case VerifiedUnencrypted = 'verified-unencrypted';
    case Incomplete = 'incomplete';
}
