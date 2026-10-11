<?php

declare(strict_types=1);

namespace App\Data;

/**
 * One page of a search (issue #1050): the release ids it holds in display order, how many
 * releases match, the page shown and the last page that can be opened. An index-backed search
 * stops at the last page the index can reach (`max_matches`); `$beyondReach` says more match than
 * that. `$available` is false when the index cannot answer and no database fallback did: the page
 * then reads "Search unavailable".
 */
final readonly class WebSearchPage
{
    /** @param  list<int>  $ids */
    public function __construct(
        public array $ids,
        public int $total,
        public int $page = 1,
        public int $lastPage = 1,
        public bool $available = true,
        public bool $beyondReach = false,
    ) {}
}
