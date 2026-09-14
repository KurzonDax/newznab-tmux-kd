<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;

/** @extends Collection<int, object> */
final class CoverBrowseResults extends Collection
{
    /** @param iterable<int, object> $items */
    public function __construct(iterable $items = [], public readonly int $total = 0)
    {
        parent::__construct($items);
    }
}
