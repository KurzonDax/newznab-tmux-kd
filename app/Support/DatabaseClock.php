<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

final class DatabaseClock
{
    /**
     * Build a database-native expression for the same instant as a PHP cutoff.
     *
     * Only the relative offset from PHP's current instant is carried across, so
     * the database evaluates the cutoff from its own clock and session timezone.
     *
     * @return array{sql: string, bindings: list<int|string>}
     */
    public static function cutoff(CarbonInterface $cutoff): array
    {
        $offsetSeconds = $cutoff->getTimestamp() - now()->getTimestamp();

        return match (DB::getDriverName()) {
            'mariadb', 'mysql' => [
                'sql' => 'DATE_ADD(NOW(), INTERVAL ? SECOND)',
                'bindings' => [$offsetSeconds],
            ],
            'pgsql' => [
                'sql' => "CURRENT_TIMESTAMP + (? * INTERVAL '1 second')",
                'bindings' => [$offsetSeconds],
            ],
            'sqlite' => [
                'sql' => 'datetime(CURRENT_TIMESTAMP, ?)',
                'bindings' => [sprintf('%+d seconds', $offsetSeconds)],
            ],
            default => throw new LogicException('Unsupported database driver for database-clock cutoff.'),
        };
    }
}
