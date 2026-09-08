<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RecoveryStatus
{
    /** @return array<string,mixed> */
    public function summary(): array
    {
        if (! Schema::hasTable('obfuscation_recovery_work')) {
            return ['available' => false];
        }
        $config = RecoveryConfig::fromSettings();
        $work = DB::table('obfuscation_recovery_work')->selectRaw('stage, status, COUNT(*) AS count, MIN(due_at) AS oldest_due_at')
            ->groupBy('stage', 'status')->get()->map(static fn (object $row): array => (array) $row)->all();
        $recent = DB::table('obfuscation_recovery_attempts')->where('created_at', '>=', now()->subMinute())
            ->selectRaw('COALESCE(SUM(connections_opened), 0) AS opens, COALESCE(SUM(socket_received_bytes), 0) AS observed_bytes, COALESCE(SUM(debited_bytes), 0) AS accounted_bytes')->first();
        $oldestHeader = DB::table('obfuscation_recovery_headers')->min('first_observed_at');
        $oldestPending = DB::table('obfuscation_recovery_work')->whereIn('status', ['pending', 'claimed'])->min('created_at');

        return ['available' => true, 'enabled' => $config->enabled, 'worker_limit' => $config->threads,
            'occupied_slots' => DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->count(),
            'active_connections' => DB::table('obfuscation_recovery_attempts')->whereNotNull('connected_at')->whereNull('closed_at')->whereNull('settled_at')->count(),
            'arrivals_last_minute' => DB::table('obfuscation_recovery_bundles')->where('kind', 'posting')->where('state', '!=', 'coalesced')->where('created_at', '>=', now()->subMinute())->count(),
            'completions_last_minute' => DB::table('obfuscation_recovery_work')->where('status', 'completed')->where('updated_at', '>=', now()->subMinute())->count(),
            'oldest_pending_age_seconds' => $oldestPending === null ? null : max(0, now()->getTimestamp() - Carbon::parse($oldestPending)->getTimestamp()),
            'retention_runway_seconds' => $oldestHeader === null ? null : max(0, Carbon::parse($oldestHeader)->getTimestamp() + $config->retentionHours * 3600 - now()->getTimestamp()),
            'account_allocation' => 'Operator must allocate ordinary threads, recovery threads and headroom within the shared provider account capacity; recovery slots alone do not establish that bound.',
            'queued_work' => $work, 'traffic' => $this->traffic(),
            'catalog' => DB::table('obfuscation_recovery_catalog')->selectRaw('provider, kind, outcome, SUM(requests) AS requests, SUM(response_bytes) AS response_bytes, SUM(unknown_response_sizes) AS unknown_response_sizes')
                ->groupBy('provider', 'kind', 'outcome')->get()->all(),
            'opens_per_second' => (int) $recent->opens / 60, 'observed_bytes_per_second' => (int) $recent->observed_bytes / 60,
            'accounted_bytes_per_second' => (int) $recent->accounted_bytes / 60,
            'publications' => $this->counts('obfuscation_recovery_publications', 'state'),
            'naming' => $this->counts('obfuscation_recovery_publications', 'identity_outcome'),
            'bundles' => $this->counts('obfuscation_recovery_bundles', 'state'),
            'file_candidates' => $this->counts('obfuscation_recovery_files', 'state'),
            'storage' => ['raw_headers' => DB::table('obfuscation_recovery_headers')->count(),
                'artifacts' => DB::table('obfuscation_recovery_artifacts')->count(),
                'artifact_bytes' => (int) DB::table('obfuscation_recovery_artifacts')->sum('bytes'),
                'references' => DB::table('obfuscation_recovery_references')->count(),
                'cached_articles' => DB::table('obfuscation_recovery_evidence')->where('state', 'valid')->count(),
                'compacted_attempts' => DB::table('obfuscation_recovery_attempts')->whereNotNull('compacted_at')->count()],
            'retention_hours' => $config->retentionHours,
            'oldest_header_observed_at' => $oldestHeader,
            'settings_diagnostics' => $config->diagnostics];
    }

    /** @return array<string,mixed> */
    public function details(int $after = 0, int $afterBundle = 0): array
    {
        $summary = $this->summary();
        if (! $summary['available']) {
            return $summary;
        }
        $groups = DB::table('usenet_groups')->where('id', '>', max(0, $after))->orderBy('id')->limit(100)
            ->get(['id', 'obfuscation_recovery_profile']);
        $ids = $groups->pluck('id')->all();
        $summary['groups'] = $groups->map(static fn (object $row): array => (array) $row)->all();
        $summary['headers'] = DB::table('obfuscation_recovery_headers')->whereIn('groups_id', $ids)
            ->selectRaw('groups_id, profile, COUNT(*) AS captured, MIN(first_observed_at) AS oldest_observed_at')
            ->groupBy('groups_id', 'profile')->get()->all();
        $summary['coverage'] = DB::table('obfuscation_recovery_scans')->whereIn('groups_id', $ids)
            ->selectRaw('groups_id, source_epoch, capture_generation, capture_outcome, complete, COUNT(*) AS ranges')
            ->groupBy('groups_id', 'source_epoch', 'capture_generation', 'capture_outcome', 'complete')->get()->all();
        $summary['reasons'] = DB::table('obfuscation_recovery_metrics')->whereIn('groups_id', $ids)->orderBy('series_digest')->get()->all();
        $summary['group_bundles'] = DB::table('obfuscation_recovery_bundles')->whereIn('groups_id', $ids)
            ->where('kind', 'posting')->where('state', '!=', 'coalesced')
            ->selectRaw('groups_id, profile, state, reason, COUNT(*) AS bundles, SUM(CASE WHEN manifest_verified_at IS NOT NULL THEN 1 ELSE 0 END) AS verified_manifests')
            ->groupBy('groups_id', 'profile', 'state', 'reason')->get()->all();
        $summary['group_files'] = DB::table('obfuscation_recovery_files as file')->join('obfuscation_recovery_bundles as bundle', function ($join): void {
            $join->on('bundle.id', '=', 'file.bundle_id')->on('bundle.revision', '=', 'file.revision');
        })->whereIn('file.groups_id', $ids)->where('bundle.state', '!=', 'coalesced')
            ->selectRaw('file.groups_id, file.profile, file.state, file.enrichment_outcome, COUNT(*) AS file_candidates')
            ->groupBy('file.groups_id', 'file.profile', 'file.state', 'file.enrichment_outcome')->get()->all();
        $summary['inventories'] = DB::table('obfuscation_recovery_publications')->selectRaw('profile, protected_files, state, COUNT(*) AS inventories')
            ->groupBy('profile', 'protected_files', 'state')->get()->all();
        $summary['gap_retries'] = DB::table('obfuscation_recovery_gaps')->whereIn('groups_id', $ids)
            ->selectRaw('groups_id, outcome, COUNT(*) AS ranges')->groupBy('groups_id', 'outcome')->get()->all();
        $summary['claims'] = DB::table('obfuscation_recovery_work as work')->join('obfuscation_recovery_bundles as bundle', 'bundle.id', '=', 'work.bundle_id')
            ->whereIn('bundle.groups_id', $ids)->where('work.status', 'claimed')->orderBy('work.id')->limit(100)
            ->get(['work.id', 'bundle.groups_id', 'bundle.profile', 'work.stage', 'work.purpose', 'work.claim_expires_at', 'work.reclaim_after'])->all();
        $summary['work_outcomes'] = DB::table('obfuscation_recovery_work as work')->join('obfuscation_recovery_bundles as bundle', 'bundle.id', '=', 'work.bundle_id')
            ->whereIn('bundle.groups_id', $ids)->whereNotNull('work.result')
            ->selectRaw('bundle.groups_id, bundle.profile, work.stage, work.result, COUNT(*) AS count')
            ->groupBy('bundle.groups_id', 'bundle.profile', 'work.stage', 'work.result')->get()->all();
        $summary['survivors'] = DB::table('obfuscation_recovery_publications')->whereIn('state', ['absorbed', 'duplicate_policy_discarded'])
            ->selectRaw('state, survivor_membership, COUNT(*) AS publications')->groupBy('state', 'survivor_membership')->get()->all();
        $summary['allowances'] = $this->allowances($ids, max(0, $afterBundle));
        $summary['next_bundle_cursor'] = count($summary['allowances']) === 100 ? $summary['allowances'][99]['bundle_id'] : null;
        $summary['group_cursor'] = max(0, $after);
        $summary['next_group_cursor'] = $groups->count() === 100 ? (int) $groups->last()->id : null;

        return $summary;
    }

    /** @param list<int> $groups
     * @return list<array<string,mixed>>
     */
    private function allowances(array $groups, int $after): array
    {
        $config = RecoveryConfig::fromSettings();

        return DB::table('obfuscation_recovery_bundles')->whereIn('groups_id', $groups)->where('state', '!=', 'coalesced')->where('id', '>', $after)
            ->orderBy('id')->limit(100)->get()->map(static function (object $bundle) use ($config): array {
                $purpose = $bundle->kind === 'gap' ? 'gap' : 'construction';
                $limit = $purpose === 'gap' ? 67108864 : ($bundle->profile === RecoveryAlgorithm::Rar->value ? $config->rarCandidateBytes : $config->mediaCandidateBytes);
                $spent = app(RecoveryBudget::class)->spent($bundle->owner_digest, $purpose);
                $enrichmentSpent = app(RecoveryBudget::class)->spent($bundle->owner_digest, 'enrichment');

                return ['bundle_id' => (int) $bundle->id, 'publication_id' => $bundle->publication_id,
                    'groups_id' => (int) $bundle->groups_id, 'profile' => $bundle->profile, 'state' => $bundle->state,
                    $purpose.'_limit_bytes' => $limit, $purpose.'_debited_bytes' => $spent,
                    $purpose.'_remaining_bytes' => max(0, $limit - $spent),
                    'enrichment_limit_bytes' => $config->enrichmentReleaseBytes, 'enrichment_debited_bytes' => $enrichmentSpent,
                    'enrichment_remaining_bytes' => max(0, $config->enrichmentReleaseBytes - $enrichmentSpent)];
            })->all();
    }

    /** @return list<array<string,mixed>> */
    private function traffic(): array
    {
        $counters = array_values(array_diff(RecoveryCompaction::COUNTERS, ['debited_bytes', 'connections_opened']));
        $additional = implode('', array_map(static fn (string $counter): string => ', SUM('.$counter.') AS '.$counter, $counters));
        $current = DB::table('obfuscation_recovery_attempts as attempt')
            ->join('obfuscation_recovery_budgets as budget', 'budget.id', '=', 'attempt.budget_id')
            ->whereNull('attempt.compacted_at')
            ->selectRaw('budget.purpose, COUNT(*) AS attempts, SUM(attempt.debited_bytes) AS accounted_bytes, SUM(attempt.connections_opened) AS connections_opened, SUM(CASE WHEN attempt.socket_received_bytes IS NULL THEN 1 ELSE 0 END) AS unknown_transport_counters'.$additional)
            ->groupBy('budget.purpose');
        $archived = DB::table('obfuscation_recovery_traffic')->selectRaw('purpose, SUM(attempts) AS attempts, SUM(debited_bytes) AS accounted_bytes, SUM(connections_opened) AS connections_opened, SUM(unknown_transport_counters) AS unknown_transport_counters'.$additional)->groupBy('purpose');

        return DB::query()->fromSub($current->unionAll($archived), 'traffic')
            ->selectRaw('purpose, SUM(attempts) AS attempts, SUM(accounted_bytes) AS accounted_bytes, SUM(connections_opened) AS connections_opened, SUM(unknown_transport_counters) AS unknown_transport_counters'.$additional)
            ->groupBy('purpose')->orderBy('purpose')->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    /** @return list<array<string,mixed>> */
    private function counts(string $table, string $column): array
    {
        return DB::table($table)->selectRaw($column.', COUNT(*) AS count')->groupBy($column)->get()
            ->map(static fn (object $row): array => (array) $row)->all();
    }
}
