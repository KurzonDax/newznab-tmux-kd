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

    public static function resolve(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::AddedNewest) : self::AddedNewest;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            'posted' => 'Posted · Newest', 'posted_oldest' => 'Posted · Oldest',
            'newest' => 'Added · Newest', 'oldest' => 'Added · Oldest',
            'title' => 'Name · A–Z', 'grabs' => 'Grabs · Most',
        ];
    }

    /** @return array{string, string} */
    public function order(bool $grouped = false): array
    {
        $column = match ($this) {
            self::PostedNewest, self::PostedOldest => 'r.postdate',
            self::AddedNewest, self::AddedOldest => 'r.adddate',
            self::Name => "COALESCE(NULLIF(TRIM(r.display_name), ''), r.searchname)",
            self::Grabs => 'r.grabs',
        };
        $ascending = in_array($this, [self::PostedOldest, self::AddedOldest, self::Name], true);
        if ($grouped) {
            $column = ($ascending ? 'MIN' : 'MAX').'('.$column.')';
        }

        return [$column, $ascending ? 'asc' : 'desc'];
    }
}
