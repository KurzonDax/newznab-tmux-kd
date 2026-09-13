<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;

final class RecoveryFrontierConflicts
{
    /** @param list<string> $kinds */
    public static function overlapping(Connection $connection, string $scope, int $first, int $last, array $kinds): Builder
    {
        $query = $connection->table('obfuscation_recovery_frontier_conflicts')->where('scope_digest', $scope)->whereIn('kind', $kinds);
        if ($connection instanceof MySqlConnection) {
            $query->forceIndex('recovery_conflict_span');
        }

        return $query->where(function (Builder $ranges) use ($first, $last): void {
            for ($bucket = 0; $bucket < 16; $bucket++) {
                $width = $bucket === 15 ? PHP_INT_MAX : 1 << (4 * ($bucket + 1));
                $ranges->orWhere(function (Builder $range) use ($bucket, $width, $first, $last): void {
                    $range->where('span_bucket', $bucket)->whereBetween('first_article', [max(1, $first - $width + 1), $last])
                        ->where('last_article', '>=', $first);
                });
            }
        });
    }
}
