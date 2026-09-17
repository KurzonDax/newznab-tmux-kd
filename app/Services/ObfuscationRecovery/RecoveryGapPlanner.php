<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Services\Binaries\BinariesConfig;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RecoveryGapPlanner
{
    public function step(int $limit = 50, ?int $deadline = null): int
    {
        $deadline ??= hrtime(true) + 60000000000;
        $config = RecoveryConfig::fromSettings();
        if (! $config->enabled || $limit < 1 || hrtime(true) >= $deadline) {
            return 0;
        }
        $queued = 0;
        foreach ($this->windows($limit, $deadline) as [$window, $needed]) {
            $queued += $this->plan($window, $config, $needed);
        }

        return $queued;
    }

    /** @return Generator<int, array{object, bool}> */
    private function windows(int $limit, int $deadline): Generator
    {
        $dueAt = now();
        $progress = DB::table('obfuscation_recovery_frontier_progress')->where('scope', 'gap-planner:candidates');
        DB::table('obfuscation_recovery_frontier_progress')->insertOrIgnore(['scope' => 'gap-planner:candidates']);
        $cursor = (int) $progress->value('cursor');
        $candidates = DB::table('obfuscation_recovery_bundles')->where('kind', 'posting')
            ->whereNotIn('state', RecoveryOwnership::INACTIVE_STATES)
            ->whereNotNull('source_epoch')->whereNotNull('groups_id')->whereNotNull('capture_generation')
            ->orderByDesc('id');
        $remaining = (clone $candidates)->when($cursor > 0, fn (Builder $query) => $query->where('id', '<', $cursor))->lazy(100);
        $wrapped = $cursor > 0 ? (clone $candidates)->where('id', '>=', $cursor)->lazy(100) : [];
        foreach ($remaining->concat($wrapped) as $candidate) {
            if (hrtime(true) >= $deadline) {
                return;
            }
            // Persist the rotation before planning so limits and deadlines cannot restart at newer candidates.
            $progress->update(['cursor' => $candidate->id]);
            $envelope = (new RecoveryFrontierRebuild)->envelope($candidate);
            if ($envelope === null) {
                continue;
            }
            $scope = RecoveryPositiveCoverage::scope($candidate->source_epoch, (int) $candidate->groups_id, (int) $candidate->capture_generation);
            $witnesses = (new RecoveryFrontierRequirement)->witnesses(DB::connection(), $scope, $envelope);
            $windows = RecoveryFrontierWindows::overlapping(DB::connection(), $candidate,
                $witnesses['left'] ?? $envelope['first_article'], $witnesses['right'] ?? $envelope['last_article'], false)
                ->where('next_gap_at', '<=', $dueAt)->orderBy('next_gap_at')->orderBy('scan_id')->limit($limit)->get();
            foreach ($windows as $window) {
                if (hrtime(true) >= $deadline) {
                    return;
                }
                yield [$window, true];
                if (--$limit === 0) {
                    return;
                }
            }
        }
        if (hrtime(true) >= $deadline) {
            return;
        }
        $windows = DB::table('obfuscation_recovery_scan_windows')->where('next_gap_at', '<=', $dueAt)
            ->orderBy('next_gap_at')->orderBy('scan_id')->limit($limit)->get();
        foreach ($windows as $window) {
            if (hrtime(true) >= $deadline) {
                return;
            }
            yield [$window, false];
        }
    }

    private function plan(object $window, RecoveryConfig $config, bool $needed): int
    {
        return DB::transaction(function () use ($window, $config, $needed): int {
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
            if ($window->next_gap_at === null || $window->next_gap_at > now()) {
                return 0;
            }
            $selection = DB::table('usenet_groups')->where('id', $window->groups_id)->value('obfuscation_recovery_profile');
            $profile = $config->admits($selection, RecoveryAlgorithm::Media->selection()) ? RecoveryAlgorithm::Media
                : ($config->admits($selection, RecoveryAlgorithm::Rar->selection()) ? RecoveryAlgorithm::Rar : null);
            DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $window->scan_id)->update(['next_gap_at' => now()->addHour()]);
            if ($profile === null) {
                return 0;
            }
            $frontier = max((int) $window->requested_last, (int) $window->frontier_last);
            $advancing = Schema::hasColumn('usenet_groups', 'last_record')
                && ! $this->scope('obfuscation_recovery_scan_windows', $window)->where('requested_last', '>', $window->requested_last)->exists();
            if ($advancing) {
                $frontier = max($frontier, (int) DB::table('usenet_groups')->where('id', $window->groups_id)->value('last_record'));
                DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $window->scan_id)->update(['frontier_last' => $frontier]);
            }
            $completedNext = $needed && $advancing ? now()->addMinutes(5) : now()->addHour();
            DB::table('obfuscation_recovery_scan_windows')->where('scan_id', $window->scan_id)->update(['next_gap_at' => $completedNext]);
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
                'next_gap_at' => $needed && $cursor <= $frontier ? now()->addSecond() : $completedNext,
            ]);

            return $queued;
        }, 1);
    }

    /** @return list<array{int,int}> */
    public function positive(object $scope, int $first, int $last): array
    {
        $scanSpanBound = BinariesConfig::fromSettings()->messageBuffer * 4;
        $startExpression = 'CASE WHEN requested_first < '.(int) $first.' THEN '.(int) $first.' ELSE requested_first END';
        $scans = $this->scope('obfuscation_recovery_scans', $scope)->where('complete', true)->where('capture_outcome', 'captured')
            ->where('requested_first', '>=', $first - $scanSpanBound)
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
