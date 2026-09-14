<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** @extends LengthAwarePaginator<int, \stdClass> */
final class ReleaseBrowserPage extends LengthAwarePaginator
{
    /**
     * @param  Collection<int, \stdClass>  $items
     * @param  array<string, mixed>  $options
     */
    public function __construct(Collection $items, int $total, int $perPage, int $page, array $options, public readonly int $hiddenCount = 0)
    {
        parent::__construct($items, $total, $perPage, $page, $options);
    }
}
