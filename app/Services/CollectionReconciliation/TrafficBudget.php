<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Services\NNTP\Contracts\ArticleReadBudget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/** Crash-safe reservations: an interrupted attempt remains charged at its maximum. */
final class TrafficBudget implements ArticleReadBudget
{
    /** @var list<string> */
    private array $reservedBuckets = [];

    public function __construct(private readonly string $decisionId) {}

    public function reserve(int $bytes): bool
    {
        if ($bytes < 1 || $this->reservedBuckets !== []) {
            throw new LogicException('invalid_traffic_reservation');
        }
        $utc = now()->utc();
        $limits = [
            'day:'.$utc->format('Y-m-d') => (int) config('collection-reconciliation.day_bytes'),
            'decision:'.$this->decisionId => (int) config('collection-reconciliation.decision_bytes'),
            'hour:'.$utc->format('Y-m-d-H') => (int) config('collection-reconciliation.hour_bytes'),
        ];
        ksort($limits);
        $reserved = DB::transaction(function () use ($limits, $bytes): bool {
            foreach ($limits as $key => $limit) {
                DB::table('reconciliation_traffic')->insertOrIgnore(['bucket' => $key]);
            }
            $rows = DB::table('reconciliation_traffic')->whereIn('bucket', array_keys($limits))
                ->orderBy('bucket')->lockForUpdate()->get();
            foreach ($rows as $row) {
                if ((int) $row->charged + $bytes > $limits[$row->bucket]) {
                    return false;
                }
            }
            foreach (array_keys($limits) as $key) {
                DB::table('reconciliation_traffic')->where('bucket', $key)->update([
                    'charged' => DB::raw('charged + '.$bytes), 'requests' => DB::raw('requests + 1'),
                ]);
            }

            return true;
        }, 3);
        if ($reserved) {
            $this->reservedBuckets = array_keys($limits);
        }
        Log::info('Collection reconciliation traffic reservation', ['decision' => $this->decisionId, 'reserved_bytes' => $reserved ? $bytes : 0, 'budget_exhausted' => ! $reserved]);

        return $reserved;
    }

    public function settle(int $reserved, int $actual): void
    {
        if ($actual < 0 || $actual > $reserved || $this->reservedBuckets === []) {
            throw new LogicException('invalid_traffic_settlement');
        }
        DB::transaction(function () use ($reserved, $actual): void {
            DB::table('reconciliation_traffic')->whereIn('bucket', $this->reservedBuckets)->orderBy('bucket')->lockForUpdate()->get();
            DB::table('reconciliation_traffic')->whereIn('bucket', $this->reservedBuckets)->update([
                'charged' => DB::raw('charged - '.($reserved - $actual)),
                'actual' => DB::raw('actual + '.$actual),
            ]);
        }, 3);
        $this->reservedBuckets = [];
        Log::info('Collection reconciliation protocol bytes', ['decision' => $this->decisionId, 'response_bytes' => $actual]);
    }
}
