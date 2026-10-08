<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Carbon;
use Pdo\Sqlite;
use PDOStatement;

/**
 * An in-memory SQLite handle that runs the API's MySQL age filter, `NOW() - INTERVAL n DAY`,
 * as the same moment measured from the test clock, so an age filter really excludes rows.
 */
final class MysqlIntervalSqlitePdo extends Sqlite
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = (string) preg_replace_callback(
            '/NOW\(\)\s*-\s*INTERVAL\s+(\d+)\s+DAY/i',
            static fn (array $match): string => "'".Carbon::now()->subDays((int) $match[1])->toDateTimeString()."'",
            $query
        );

        return parent::prepare($query, $options);
    }
}
