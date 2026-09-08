<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecoveryCompaction
{
    public const int DETAIL_DAYS = 30;

    public const array COUNTERS = ['reserved_bytes', 'debited_bytes', 'decoded_bytes', 'plaintext_bytes', 'encrypted_bytes',
        'socket_received_bytes', 'transmitted_bytes', 'connections_opened', 'elapsed_milliseconds', 'late_receive_allowance'];

    public function step(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('invalid_compaction_batch');
        }

        return DB::transaction(function () use ($limit): int {
            $rows = DB::table('obfuscation_recovery_attempts')->whereNull('compacted_at')
                ->where('created_at', '<=', now()->subDays(self::DETAIL_DAYS))->whereNotNull('settled_at')
                ->orderBy('created_at')->orderBy('id')->limit($limit)->lockForUpdate()->get();
            $purposes = DB::table('obfuscation_recovery_budgets')->whereIn('id', $rows->pluck('budget_id'))->pluck('purpose', 'id');
            foreach ($rows as $row) {
                $dimensions = ['day' => substr($row->created_at, 0, 10), 'groups_id' => $row->groups_id,
                    'profile' => $row->profile, 'purpose' => $purposes[$row->budget_id], 'provider' => $row->provider ?? 'unknown',
                    'outcome' => $row->outcome, 'failure_phase' => $row->failure_phase ?? 'none'];
                $digest = (new RecoveryIdentity)->digest(array_map(strval(...), array_values($dimensions)));
                DB::table('obfuscation_recovery_traffic')->insertOrIgnore(['series_digest' => $digest, ...$dimensions]);
                $aggregate = DB::table('obfuscation_recovery_traffic')->where('series_digest', $digest)->lockForUpdate()->first();
                $values = ['attempts' => (int) $aggregate->attempts + 1,
                    'unknown_transport_counters' => (int) $aggregate->unknown_transport_counters + (int) ($row->socket_received_bytes === null)];
                foreach (self::COUNTERS as $column) {
                    $values[$column] = (int) $aggregate->{$column} + (int) $row->{$column};
                }
                DB::table('obfuscation_recovery_traffic')->where('series_digest', $digest)->update($values);
                DB::table('obfuscation_recovery_attempts')->where('id', $row->id)->whereNull('compacted_at')->update([
                    'provider' => null, 'decoded_bytes' => null, 'plaintext_bytes' => null, 'encrypted_bytes' => null,
                    'socket_received_bytes' => null, 'transmitted_bytes' => null, 'connections_opened' => 0,
                    'receive_window' => null, 'buffered_at_close' => null, 'elapsed_milliseconds' => null,
                    'connected_at' => null, 'closed_at' => null, 'failure_phase' => null, 'compacted_at' => now(),
                ]);
            }

            return $rows->count();
        }, 1);
    }

    public function coverage(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('invalid_compaction_batch');
        }

        return DB::transaction(function () use ($limit): int {
            $rows = DB::table('obfuscation_recovery_scans')->whereNull('compacted_at')
                ->where('created_at', '<=', now()->subDays(self::DETAIL_DAYS))->orderBy('created_at')->orderBy('id')
                ->limit($limit)->lockForUpdate()->get();
            foreach ($rows as $row) {
                $returned = json_decode($row->returned_ranges ?? '[]', true, flags: JSON_THROW_ON_ERROR);
                $missing = json_decode($row->missing_ranges ?? '[]', true, flags: JSON_THROW_ON_ERROR);
                $count = static fn (array $ranges): int => array_sum(array_map(static fn (array $range): int => $range[1] - $range[0] + 1, $ranges));
                $values = ['date_points' => null, 'returned_articles' => $count($returned), 'missing_articles' => $count($missing), 'compacted_at' => now()];
                if ($row->capture_outcome === 'raw_expired' && $row->date_order_consistent
                    && ($row->last_postdate === null || $row->last_postdate <= now()->format('Y-m-d H:i:s'))) {
                    $values['returned_ranges'] = null;
                    $values['missing_ranges'] = null;
                }
                DB::table('obfuscation_recovery_scans')->where('id', $row->id)->update($values);
            }

            return $rows->count();
        }, 1);
    }
}
