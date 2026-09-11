<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Support\DatabaseClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CollectionQuietPredicate
{
    /** @return array{sql: string, bindings: list<int|string>} */
    public static function build(int $hours, string $alias = 'collections', string $legacyColumn = 'dateadded', bool $currentRead = false): array
    {
        $cutoff = DatabaseClock::cutoff(now()->subHours($hours));
        $clock = Schema::hasColumn('collections', 'last_seen_at')
            ? "COALESCE({$alias}.last_seen_at, {$alias}.dateadded, {$alias}.added)"
            : "{$alias}.{$legacyColumn}";
        $wall = $clock.' < '.$cutoff['sql'];
        if (! Schema::hasColumns('collections', ['last_seen_head_postdate', 'last_seen_tail_postdate'])
            || ! Schema::hasColumn('usenet_groups', 'backfill_settled_at')) {
            return ['sql' => $wall, 'bindings' => $cutoff['bindings']];
        }

        $head = "{$alias}.last_seen_head_postdate";
        $tail = "{$alias}.last_seen_tail_postdate";
        $headLimit = DB::getDriverName() === 'sqlite'
            ? "datetime({$head}, ? || ' hours')"
            : "DATE_ADD({$head}, INTERVAL ? HOUR)";
        $tailLimit = DB::getDriverName() === 'sqlite'
            ? "datetime({$tail}, ? || ' hours')"
            : "DATE_ADD({$tail}, INTERVAL ? HOUR)";
        $lock = $currentRead && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? ' FOR UPDATE' : '';
        $groups = DB::connection()->getQueryGrammar()->wrapTable('usenet_groups');

        return [
            'sql' => "(({$head} IS NULL AND {$tail} IS NULL AND {$wall})
                OR (({$head} IS NOT NULL OR {$tail} IS NOT NULL) AND EXISTS (
                    SELECT 1 FROM {$groups} g WHERE g.id = {$alias}.groups_id
                    AND ({$head} IS NULL
                        OR (g.active = 1 AND g.last_record_postdate >= {$headLimit})
                        OR (g.active = 0 AND {$wall}))
                    AND ({$tail} IS NULL
                        OR (g.backfill = 1 AND g.backfill_settled_at IS NULL AND g.first_record_postdate <= {$tailLimit})
                        OR ((g.backfill = 0 OR g.backfill_settled_at IS NOT NULL) AND {$wall}))
                {$lock})))",
            'bindings' => [...$cutoff['bindings'], $hours, ...$cutoff['bindings'], -$hours, ...$cutoff['bindings']],
        ];
    }
}
