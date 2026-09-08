<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RecoveryGapPlanner
{
    public function step(): int
    {
        $config = RecoveryConfig::fromSettings();
        if (! $config->enabled) {
            return 0;
        }
        $window = DB::table('obfuscation_recovery_scan_windows')->where('next_gap_at', '<=', now())
            ->orderBy('next_gap_at')->orderBy('scan_id')->first();
        if ($window === null) {
            return 0;
        }

        return DB::transaction(function () use ($window, $config): int {
            $control = DB::table('obfuscation_recovery_controls')->where('scope', 'group:'.$window->groups_id)->lockForUpdate()->first();
            $window = DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $window->scan_id)->lockForUpdate()->first();
            $epoch = DB::table('obfuscation_recovery_controls')->where('scope', 'primary')->value('epoch');
            if ($window === null || $control === null || $epoch !== $window->source_epoch
                || (int) $control->generation !== (int) $window->capture_generation || $window->expires_at <= now()) {
                if ($window !== null) {
                    DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $window->scan_id)->update(['next_gap_at' => null]);
                }

                return 0;
            }
            $selection = DB::table('usenet_groups')->where('id', $window->groups_id)->value('obfuscation_recovery_profile');
            $profile = $config->admits($selection, RecoveryAlgorithm::Media->selection()) ? RecoveryAlgorithm::Media
                : ($config->admits($selection, RecoveryAlgorithm::Rar->selection()) ? RecoveryAlgorithm::Rar : null);
            DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $window->scan_id)->update(['next_gap_at' => now()->addMinutes(5)]);
            if ($profile === null) {
                return 0;
            }
            $frontier = max((int) $window->requested_last, (int) $window->frontier_last);
            if (Schema::hasColumn('usenet_groups', 'last_record')
                && ! $this->scope('obfuscation_recovery_scan_windows', $window)->where('requested_last', '>', $window->requested_last)->exists()) {
                $frontier = max($frontier, (int) DB::table('usenet_groups')->where('id', $window->groups_id)->value('last_record'));
                DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $window->scan_id)->update(['frontier_last' => $frontier]);
            }
            $previous = $this->scope('obfuscation_recovery_scan_windows', $window)->where('requested_first', '<', $window->requested_first)->max('requested_last');
            $first = $window->gap_cursor === null ? min((int) $window->requested_first, $previous === null ? (int) $window->requested_first : (int) $previous + 1) : (int) $window->gap_cursor;
            $last = min($frontier, $first + 19999);
            if ($first > $last) {
                return 0;
            }
            $positive = $this->positive($window, $first, $last);
            $ranges = DB::table('obfuscation_recovery_gaps')->where('groups_id', $window->groups_id)->where('source_epoch', $window->source_epoch)->where('requested_first', '<=', $last)->where('requested_last', '>=', $first)
                ->limit(20001)->get(['requested_first', 'requested_last']);
            if ($ranges->count() > 20000) {
                return 0;
            }
            foreach ($ranges as $range) {
                $positive[] = [(int) $range->requested_first, (int) $range->requested_last];
            }
            $holes = RecoveryCoverage::holes($first, $last, $positive);
            $queued = 0;
            foreach (array_slice($holes, 0, 10) as [$start, $end]) {
                $owner = (new RecoveryIdentity)->digest(['gap', $window->source_epoch, (string) $window->groups_id,
                    (string) $window->capture_generation, (string) $start, (string) $end]);
                $ownerDigest = (new RecoveryIdentity)->digest(['work-owner', $owner]);
                DB::table('obfuscation_recovery_bundles')->insertOrIgnore([
                    'owner_digest' => $ownerDigest, 'revision' => 1, 'kind' => 'gap', 'groups_id' => $window->groups_id,
                    'profile' => $profile->value, 'source_epoch' => $window->source_epoch,
                    'capture_generation' => $window->capture_generation, 'state' => 'gap_pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $bundleId = (int) DB::table('obfuscation_recovery_bundles')->where('owner_digest', $ownerDigest)->value('id');
                app(RecoveryWork::class)->enqueueForBundle(RecoveryStage::Download, $bundleId, 1, 'gap', ['first' => $start, 'last' => $end]);
                DB::table('obfuscation_recovery_gaps')->insertOrIgnore([
                    'bundle_id' => $bundleId, 'groups_id' => $window->groups_id, 'source_epoch' => $window->source_epoch,
                    'capture_generation' => $window->capture_generation, 'requested_first' => $start, 'requested_last' => $end,
                    'expires_at' => $window->expires_at, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $queued++;
            }
            $cursor = count($holes) > 10 ? $holes[10][0] : $last + 1;
            DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $window->scan_id)->update([
                'gap_cursor' => $cursor > $frontier ? null : $cursor,
                'next_gap_at' => $cursor > $frontier ? now()->addMinutes(5) : now()->addSecond(),
            ]);

            return $queued;
        }, 1);
    }

    /** @return list<array{int,int}> */
    public function positive(object $scope, int $first, int $last): array
    {
        $startExpression = 'CASE WHEN requested_first < '.(int) $first.' THEN '.(int) $first.' ELSE requested_first END';
        $scans = $this->scope('obfuscation_recovery_scans', $scope)->where('complete', true)->where('capture_outcome', 'captured')
            ->where('requested_first', '<=', $last)->where('requested_last', '>=', $first)
            ->selectRaw($startExpression.' AS covered_first, MAX(requested_last) AS covered_last')
            ->groupByRaw($startExpression)->orderBy('covered_first')->cursor();
        $ranges = [];
        foreach ($scans as $scan) {
            $start = (int) $scan->covered_first;
            $end = min((int) $scan->covered_last, $last);
            $index = count($ranges) - 1;
            if ($index >= 0 && $start <= $ranges[$index][1] + 1) {
                $ranges[$index][1] = max($ranges[$index][1], $end);
            } else {
                $ranges[] = [$start, $end];
            }
        }

        return RecoveryCoverage::merge($ranges);
    }

    private function scope(string $table, object $scope): Builder
    {
        return DB::table($table)->where('groups_id', $scope->groups_id)->where('source_epoch', $scope->source_epoch)
            ->where('capture_generation', $scope->capture_generation);
    }
}
