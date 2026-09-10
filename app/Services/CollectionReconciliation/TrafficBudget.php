<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Services\NNTP\Contracts\ArticleReadBudget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogicException;

/** Crash-safe reservations: an interrupted attempt remains charged at its maximum. */
final class TrafficBudget implements ArticleReadBudget
{
    /** @var list<string> */
    private array $reservedBuckets = [];

    private ?string $denial = null;

    public function __construct(private readonly string $decisionId) {}

    public function denialReason(): ?string
    {
        return $this->denial;
    }

    public function reserve(int $bytes): bool
    {
        if ($bytes < 1 || $this->reservedBuckets !== []) {
            throw new LogicException('invalid_traffic_reservation');
        }
        $utc = now()->utc();
        $this->denial = null;
        $effective = new ReconciliationLimits;
        $limits = [
            'day:'.$utc->format('Y-m-d') => $effective->dayBytes(),
            'decision:'.$this->decisionId => (int) config('collection-reconciliation.decision_bytes'),
            'hour:'.$utc->format('Y-m-d-H') => $effective->hourBytes(),
        ];
        ksort($limits);
        $reserved = DB::transaction(function () use ($limits, $bytes): bool {
            foreach ($limits as $key => $limit) {
                DB::table('reconciliation_traffic')->insertOrIgnore(['bucket' => $key]);
            }
            $rows = DB::table('reconciliation_traffic')->whereIn('bucket', array_keys($limits))
                ->orderBy('bucket')->lockForUpdate()->get()->keyBy('bucket');
            $decisionKey = 'decision:'.$this->decisionId;
            if ($bytes > $limits[$decisionKey] - (int) $rows[$decisionKey]->charged) {
                $this->denial = 'decision_budget_exhausted';

                return false;
            }
            foreach ($rows as $row) {
                if ($bytes > $limits[$row->bucket] - (int) $row->charged) {
                    $this->denial = 'budget_exhausted';
                    if (Schema::hasTable('reconciliation_budget_deferrals')) {
                        foreach (array_keys($limits) as $key) {
                            if ($key !== $decisionKey) {
                                DB::table('reconciliation_budget_deferrals')->insertOrIgnore([
                                    'bucket' => $key, 'decision_id' => hash('sha256', $this->decisionId),
                                ]);
                            }
                        }
                    }

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
