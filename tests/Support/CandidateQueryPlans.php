<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

final class CandidateQueryPlans
{
    /**
     * Analyze each complete candidate SQL shape after measuring the original calls.
     *
     * @param  list<array{query: string, bindings: array, time: float}>  $queries
     * @return list<array{table: string, access: string|null, key: string|null, rows_times_loops: float}>
     */
    public static function inspect(array $queries): array
    {
        $seen = [];
        $nodes = [];
        foreach ($queries as $query) {
            $sql = $query['query'];
            if (isset($seen[$sql]) || ! str_starts_with(strtolower($sql), 'select ')
                || str_contains(strtolower($sql), 'information_schema')) {
                continue;
            }
            $seen[$sql] = true;
            $result = (array) DB::selectOne('ANALYZE FORMAT=JSON '.$sql, $query['bindings']);
            $plan = json_decode((string) reset($result), true, flags: JSON_THROW_ON_ERROR);
            self::collect($plan, $nodes);
        }

        return $nodes;
    }

    private static function collect(array $plan, array &$nodes): void
    {
        if (isset($plan['table_name'])) {
            $nodes[] = ['table' => $plan['table_name'], 'access' => $plan['access_type'] ?? null,
                'key' => $plan['key'] ?? null,
                'rows_times_loops' => (float) ($plan['r_rows'] ?? 0) * (float) ($plan['r_loops'] ?? 0)];
        }
        foreach ($plan as $child) {
            if (is_array($child)) {
                self::collect($child, $nodes);
            }
        }
    }
}
