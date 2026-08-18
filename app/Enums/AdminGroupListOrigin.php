<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminGroupListOrigin: string
{
    case ALL = 'all';
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? self::tryFrom($value) ?? self::ALL : self::ALL;
    }

    public function routeName(): string
    {
        return match ($this) {
            self::ALL => 'admin.group-list',
            self::ACTIVE => 'admin.group-list-active',
            self::INACTIVE => 'admin.group-list-inactive',
        };
    }
}
