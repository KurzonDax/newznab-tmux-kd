<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;

final class RecoveryFrontierWindows
{
    public static function overlapping(Connection $connection, object $bundle, int $first, int $last, bool $sealed): Builder
    {
        $query = $connection->table('obfuscation_recovery_scan_windows')->where('source_epoch', $bundle->source_epoch)
            ->where('groups_id', $bundle->groups_id)->where('capture_generation', $bundle->capture_generation)
            ->when(! $sealed, fn (Builder $query) => $query->where('expires_at', '>', now()));
        if ($connection instanceof MySqlConnection) {
            $query->forceIndex('recovery_window_span');
        }

        return $query->where(function (Builder $ranges) use ($first, $last): void {
            for ($bucket = 0; $bucket < 16; $bucket++) {
                $width = $bucket === 15 ? PHP_INT_MAX : 1 << (4 * ($bucket + 1));
                $ranges->orWhere(fn (Builder $range) => $range->where('span_bucket', $bucket)
                    ->whereBetween('requested_first', [max(1, $first - $width + 1), $last])->where('requested_last', '>=', $first));
            }
        });
    }
}
