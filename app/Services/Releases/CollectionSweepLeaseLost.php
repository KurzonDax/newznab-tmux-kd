<?php

declare(strict_types=1);

namespace App\Services\Releases;

use RuntimeException;

final class CollectionSweepLeaseLost extends RuntimeException
{
    public int $deleted = 0;

    public function __construct()
    {
        parent::__construct('lease_lost');
    }
}
