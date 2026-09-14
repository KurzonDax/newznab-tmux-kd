<?php

declare(strict_types=1);

namespace App\Support;

final class ReleaseSize
{
    public static function format(int|float $bytes): string
    {
        $gigabytes = $bytes >= 1073741824;

        return number_format(max(0, $bytes) / ($gigabytes ? 1073741824 : 1048576), 2)
            .($gigabytes ? ' GB' : ' MB');
    }
}
