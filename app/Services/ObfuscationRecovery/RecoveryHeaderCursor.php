<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Generator;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final class RecoveryHeaderCursor
{
    /** @return Generator<int,object> */
    public function rows(Builder $base, bool $reverse = false, int $maximum = 500001): Generator
    {
        if ($maximum < 1 || $maximum > 500001) {
            throw new InvalidArgumentException('invalid_header_cursor_cap');
        }
        $direction = $reverse ? 'desc' : 'asc';
        $operator = $reverse ? '<' : '>';
        $previous = null;
        $seen = 0;
        while ($seen < $maximum) {
            $query = clone $base;
            if ($previous !== null) {
                $query->where(function (Builder $query) use ($previous, $operator): void {
                    $query->where('embedded_timestamp_ms', $operator, $previous->embedded_timestamp_ms)
                        ->orWhere(function (Builder $query) use ($previous, $operator): void {
                            $query->where('embedded_timestamp_ms', $previous->embedded_timestamp_ms)->where('message_id', $operator, $previous->message_id);
                        });
                });
            }
            $limit = min(1000, $maximum - $seen);
            $rows = $query->reorder('embedded_timestamp_ms', $direction)->orderBy('message_id', $direction)->limit($limit)->get();
            foreach ($rows as $row) {
                yield $row;
                $seen++;
                $previous = $row;
            }
            if ($rows->count() < $limit) {
                break;
            }
        }
    }
}
