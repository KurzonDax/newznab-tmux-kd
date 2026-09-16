<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecoveryRetention
{
    /**
     * Keep the default batch small to release group locks within capture's one-second wait budget.
     *
     * @return array{headers:int,candidates:int,waiting:int}
     */
    public function purge(RecoveryConfig $config, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('invalid_retention_batch');
        }
        $cutoff = now()->subHours(min($config->retentionHours, 876000));

        return DB::transaction(function () use ($cutoff, $limit): array {
            $rows = DB::table('obfuscation_recovery_headers')->where('first_observed_at', '<=', $cutoff)
                ->orderBy('first_observed_at')->orderBy('id')->limit($limit)->get();
            foreach ($rows->pluck('groups_id')->unique()->sort()->all() as $group) {
                DB::table('obfuscation_recovery_controls')->where('scope', 'group:'.$group)->lockForUpdate()->first();
            }
            $rows = DB::table('obfuscation_recovery_headers')->whereIn('id', $rows->pluck('id')->all())
                ->where('first_observed_at', '<=', $cutoff)->orderBy('first_observed_at')->orderBy('id')->lockForUpdate()->get();
            $report = ['headers' => 0, 'candidates' => 0, 'waiting' => 0];
            $waiting = [];
            $membership = DB::table('obfuscation_recovery_bundles as owner')->join('obfuscation_recovery_headers as raw', function ($join): void {
                $join->on('owner.groups_id', '=', 'raw.groups_id')->on('owner.source_epoch', '=', 'raw.source_epoch')
                    ->on('owner.capture_generation', '=', 'raw.capture_generation')->on('owner.profile', '=', 'raw.profile')
                    ->on('owner.start_ms', '<=', 'raw.embedded_timestamp_ms')->on('owner.end_ms', '>=', 'raw.embedded_timestamp_ms')
                    ->where(fn ($partition) => $partition->where('owner.profile', RecoveryAlgorithm::Media->value)
                        ->orWhereColumn('owner.key_digest', 'raw.key_digest'));
            })->whereIn('raw.id', $rows->pluck('id')->all())->where('owner.state', '!=', 'coalesced');
            $discovered = (clone $membership)
                ->distinct()->limit(1001)->get(['owner.id', 'owner.groups_id', 'owner.source_epoch', 'owner.capture_generation', 'owner.profile', 'owner.start_ms', 'owner.end_ms']);
            if ($discovered->count() > 1000) {
                return ['headers' => 0, 'candidates' => 0, 'waiting' => 1001];
            }
            $bundleIds = $rows->pluck('bundle_id')->merge($discovered->pluck('id'))->filter()->unique()->sort()->values()->all();
            foreach ($bundleIds as $id) {
                $bundle = DB::table('obfuscation_recovery_bundles')->where('id', $id)->lockForUpdate()->first();
                if ($bundle === null || ($bundle->manifest_verified_at !== null && $bundle->sealed_plan !== null
                    && in_array($bundle->state, ['ready', 'publishing', 'published'], true))) {
                    continue;
                }
                if (! in_array($bundle->state, RecoveryOwnership::INACTIVE_STATES, true)) {
                    DB::table('obfuscation_recovery_bundles')->where('id', $id)->update([
                        'state' => 'expiry_pending', 'reason' => 'raw_retention_elapsed', 'updated_at' => now(),
                    ]);
                    $bundle->state = 'expiry_pending';
                    $this->count((int) ($bundle->groups_id ?? $rows->firstWhere('bundle_id', $id)->groups_id),
                        $bundle->profile ?? $rows->firstWhere('bundle_id', $id)->profile, 'expired_candidates', 1);
                    $report['candidates']++;
                }
                DB::table('obfuscation_recovery_work')->where('bundle_id', $id)->where('status', 'pending')
                    ->update(['status' => 'obsolete', 'updated_at' => now()]);
                $live = DB::table('obfuscation_recovery_work')->where('bundle_id', $id)->where('status', 'claimed')
                    ->where('claim_expires_at', '>', now())->exists();
                if ($live || ($bundle->claim_token !== null && $bundle->claim_expires_at !== null && $bundle->claim_expires_at > now()->format('Y-m-d H:i:s.u'))) {
                    $waiting[$id] = true;
                    $report['waiting']++;

                    continue;
                }
                DB::table('obfuscation_recovery_work')->where('bundle_id', $id)->whereIn('status', ['claimed', 'pending'])
                    ->update(['status' => 'obsolete', 'claim_token' => null, 'claim_expires_at' => null, 'updated_at' => now()]);
                if ($bundle->state === 'expiry_pending') {
                    DB::table('obfuscation_recovery_bundles')->where('id', $id)->update([
                        'state' => 'expired_unresolved', 'revision' => (int) $bundle->revision + 1, 'sealed_plan' => null,
                        'manifest_verified_at' => null, 'snapshot_digest' => null, 'claim_token' => null,
                        'claim_expires_at' => null, 'updated_at' => now(),
                    ]);
                }
            }
            $waitingHeaders = array_fill_keys((clone $membership)->whereIn('owner.id', array_keys($waiting))->distinct()->pluck('raw.id')->all(), true);
            $totals = $removed = [];
            foreach ($rows as $row) {
                if (($row->bundle_id !== null && isset($waiting[$row->bundle_id])) || isset($waitingHeaders[$row->id])) {
                    continue;
                }
                DB::table('obfuscation_recovery_expired_headers')->insertOrIgnore([
                    'source_epoch' => $row->source_epoch, 'groups_id' => $row->groups_id,
                    'message_id_digest' => $row->message_id_digest, 'first_observed_at' => $row->first_observed_at,
                ]);
                $deleted = DB::table('obfuscation_recovery_headers')->where('id', $row->id)->where('first_observed_at', '<=', $cutoff)->delete();
                if ($deleted === 0) {
                    continue;
                }
                DB::table('obfuscation_recovery_scans')->where('source_epoch', $row->source_epoch)
                    ->where('groups_id', $row->groups_id)->where('capture_generation', $row->capture_generation)
                    ->where('requested_first', '<=', $row->article_number)->where('requested_last', '>=', $row->article_number)
                    ->update(['complete' => false, 'capture_outcome' => 'raw_expired', 'compacted_at' => null]);
                $removed[] = (array) $row;
                $key = $row->groups_id.':'.$row->profile;
                $totals[$key] ??= ['group' => (int) $row->groups_id, 'profile' => $row->profile, 'count' => 0];
                $totals[$key]['count']++;
                $report['headers']++;
            }
            $coverage = new RecoveryPositiveCoverage;
            foreach (collect($removed)->groupBy(fn (array $row): string => RecoveryPositiveCoverage::scope(
                $row['source_epoch'], (int) $row['groups_id'], (int) $row['capture_generation'])) as $scopeRows) {
                $scope = $scopeRows->first();
                // Read the current minimum after acquiring group locks, including other generations and live claims.
                $floor = DB::table('obfuscation_recovery_headers')->where('source_epoch', $scope['source_epoch'])
                    ->where('groups_id', $scope['groups_id'])->orderBy('article_number')->lockForUpdate()->value('article_number');
                // Preserve frontier witnesses below the raw floor: only an actually expired article
                // establishes a hole that no surviving candidate's coverage island can cross.
                $expiredBoundary = $scopeRows->filter(fn (array $row): bool => $floor !== null
                    && (int) $row['article_number'] < (int) $floor)->max('article_number');
                if ($floor === null || $expiredBoundary !== null) {
                    $coverage->trim(DB::connection(), $scope['source_epoch'], (int) $scope['groups_id'],
                        (int) $scope['capture_generation'], $floor === null ? null : (int) $expiredBoundary + 1);
                }
                foreach ($scopeRows as $row) {
                    if ($floor !== null && (int) $row['article_number'] >= (int) $floor) {
                        $coverage->expire(DB::connection(), $row['source_epoch'], (int) $row['groups_id'],
                            (int) $row['capture_generation'], (int) $row['article_number']);
                    }
                }
            }
            RecoveryDirty::mark(DB::connection(), $removed);
            foreach ($totals as $total) {
                $this->count($total['group'], $total['profile'], 'expired_headers', $total['count']);
            }

            return $report;
        }, 1);
    }

    private function count(int $group, string $profile, string $metric, int $value): void
    {
        $reason = 'raw_retention_elapsed';
        $digest = (new RecoveryIdentity)->digest([(string) $group, $profile, $reason, $metric]);
        DB::table('obfuscation_recovery_metrics')->upsert([
            'series_digest' => $digest, 'groups_id' => $group, 'profile' => $profile, 'reason' => $reason,
            'metric' => $metric, 'value' => 0, 'updated_at' => now(),
        ], ['series_digest'], ['updated_at']);
        DB::table('obfuscation_recovery_metrics')->where('series_digest', $digest)->increment('value', $value);
    }
}
