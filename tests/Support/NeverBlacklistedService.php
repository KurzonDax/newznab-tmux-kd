<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\BlacklistService;

/**
 * Keeps header parsing away from the binaryblacklist table.
 */
final class NeverBlacklistedService extends BlacklistService
{
    public function isBlackListed(array $msg, string $groupName): bool
    {
        return false;
    }
}
