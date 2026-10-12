<?php

declare(strict_types=1);

namespace App\Enums;

enum ReleaseSort: string
{
    case PostedNewest = 'posted';
    case PostedOldest = 'posted_oldest';
    case AddedNewest = 'newest';
    case AddedOldest = 'oldest';
    case Name = 'title';
    case Grabs = 'grabs';

    /** @return array{string, string} */
    public function order(): array
    {
        $column = match ($this) {
            self::PostedNewest, self::PostedOldest => 'r.postdate',
            self::AddedNewest, self::AddedOldest => 'r.adddate',
            self::Name => "COALESCE(NULLIF(TRIM(r.display_name), ''), r.searchname)",
            self::Grabs => 'r.grabs',
        };
        $ascending = in_array($this, [self::PostedOldest, self::AddedOldest, self::Name], true);

        return [$column, $ascending ? 'asc' : 'desc'];
    }
}
