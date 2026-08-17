<?php

declare(strict_types=1);

namespace App\Enums;

enum BackupKind: string
{
    case Full = 'full';
    case Daily = 'daily';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
