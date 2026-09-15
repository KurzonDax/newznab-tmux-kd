<?php

declare(strict_types=1);

namespace Tests\Support;

use php_user_filter;

final class LogReadFilter extends php_user_filter
{
    public static int $bytes = 0;

    public static bool $closed = false;

    public function filter(mixed $in, mixed $out, mixed &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            self::$bytes += $bucket->datalen;
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }

    public function onClose(): void
    {
        self::$closed = true;
    }
}
