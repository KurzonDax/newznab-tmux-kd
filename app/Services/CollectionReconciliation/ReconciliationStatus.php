<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ReconciliationStatus
{
    /** @return array{available: bool, hour: array{used: int, limit: int, deferrals: int}, day: array{used: int, limit: int, deferrals: int}} */
    public function summary(): array
    {
        $available = Schema::hasTable('reconciliation_traffic') && Schema::hasTable('reconciliation_budget_deferrals');
        $utc = now()->utc();
        $limits = new ReconciliationLimits;
        $hour = $this->window('hour:'.$utc->format('Y-m-d-H'), $limits->hourBytes(), $available);
        $day = $this->window('day:'.$utc->format('Y-m-d'), $limits->dayBytes(), $available);

        return ['available' => $available, 'hour' => $hour, 'day' => $day];
    }

    /** @return array{used: int, limit: int, deferrals: int} */
    private function window(string $bucket, int $limit, bool $available): array
    {
        return [
            'used' => $available ? (int) DB::table('reconciliation_traffic')->where('bucket', $bucket)->value('charged') : 0,
            'limit' => $limit,
            'deferrals' => $available ? DB::table('reconciliation_budget_deferrals')->where('bucket', $bucket)->count() : 0,
        ];
    }
}
