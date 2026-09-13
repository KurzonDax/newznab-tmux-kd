<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;

final class RecoveryFrontierRebuild
{
    public const string PURPOSE = 'frontier_rebuild';

    /** @return array<string,int> */
    public function step(): array
    {
        $report = [];
        if (! RecoveryConfig::fromSettings()->enabled) {
            return $report;
        }
        $report['frontier_conflicts_retired'] = $this->retire();
        foreach (['collecting', 'ready', 'publishing'] as $state) {
            $scope = 'candidates:'.RecoveryFrontiers::VERSION.':'.$state;
            DB::table('obfuscation_recovery_frontier_progress')->insertOrIgnore(['scope' => $scope]);
            $cursor = (int) DB::table('obfuscation_recovery_frontier_progress')->where('scope', $scope)->value('cursor');
            $ids = DB::table('obfuscation_recovery_bundles')->where('kind', 'posting')->where('state', $state)
                ->where('id', '>', $cursor)->orderBy('id')->limit(2)->pluck('id');
            foreach ($ids as $id) {
                $group = DB::table('obfuscation_recovery_bundles')->where('id', $id)->value('groups_id');
                $outcome = DB::transaction(fn (): string => $this->candidate((int) $id, (int) $group), 1);
                $report[$outcome] = ($report[$outcome] ?? 0) + 1;
            }
            DB::table('obfuscation_recovery_frontier_progress')->where('scope', $scope)->update(['cursor' => $ids->last() ?? 0]);
        }

        return $report;
    }

    private function retire(): int
    {
        return DB::transaction(function (): int {
            DB::table('obfuscation_recovery_frontier_progress')->upsert(['scope' => 'retirement'], ['scope'], ['scope']);
            $progress = DB::table('obfuscation_recovery_frontier_progress')->where('scope', 'retirement')->lockForUpdate()->first();
            $range = DB::table('obfuscation_recovery_frontier_ranges')->where('id', '>', $progress->cursor)
                ->where('evidence_version', RecoveryFrontiers::VERSION)->orderBy('id')->first();
            if ($range === null) {
                DB::table('obfuscation_recovery_frontier_progress')->where('scope', 'retirement')->update(['cursor' => 0]);

                return 0;
            }
            DB::table('obfuscation_recovery_controls')->where('scope', $range->scope_digest)->lockForUpdate()->first();
            $query = RecoveryFrontierConflicts::overlapping(DB::connection(), $range->scope_digest,
                (int) $range->first_article, (int) $range->last_article, ['unknown', 'ordering']);
            $before = count((clone $query)->limit(201)->pluck('identity')->all());
            (new RecoveryFrontiers)->replaceLegacy(DB::connection(), $range->scope_digest, (int) $range->first_article, (int) $range->last_article);
            if (! $query->exists()) {
                DB::table('obfuscation_recovery_frontier_progress')->where('scope', 'retirement')->update(['cursor' => $range->id]);
            }

            return min(200, $before);
        }, 1);
    }

    private function candidate(int $id, int $group): string
    {
        $control = DB::table('obfuscation_recovery_controls')->where('scope', 'group:'.$group)->lockForUpdate()->first();
        $bundle = DB::table('obfuscation_recovery_bundles')->where('id', $id)->lockForUpdate()->first();
        if ($bundle === null || $control === null || (int) $bundle->groups_id !== $group || ! in_array($bundle->state, ['collecting', 'ready', 'publishing'], true)
            || ! RecoveryAdmission::allows((int) $bundle->groups_id, RecoveryAlgorithm::from($bundle->profile))) {
            return 'frontier_rebuild_unresolved';
        }
        if (DB::table('obfuscation_recovery_work')->where('bundle_id', $id)->where('status', 'claimed')->where('claim_expires_at', '>', now())->exists()
            || ($bundle->claim_token !== null && $bundle->claim_expires_at > now()->format('Y-m-d H:i:s.u'))) {
            return 'frontier_rebuild_claimed';
        }
        $sealed = $this->sealed($bundle);
        if (DB::table('obfuscation_recovery_controls')->where('scope', 'primary')->value('epoch') !== $bundle->source_epoch) {
            return $this->reason($bundle, 'frontier_source_epoch_unavailable');
        }
        if (! $sealed && (int) $control->generation !== (int) $bundle->capture_generation) {
            return $this->reason($bundle, 'frontier_capture_scope_changed');
        }
        $envelope = $this->envelope($bundle);
        if ($envelope === null) {
            return 'frontier_rebuild_unresolved';
        }
        $assessment = (new RecoverySettlement)->assess($bundle->source_epoch, (int) $bundle->groups_id, (int) $bundle->capture_generation,
            $envelope['first_article'], $envelope['last_article'], $envelope['first_postdate'], $envelope['last_postdate'], $envelope['changed_at'], $sealed, $bundle);
        if ($assessment === 'ready') {
            $stage = $sealed ? RecoveryStage::Publish : RecoveryStage::Discover;
            $workId = app(RecoveryWork::class)->enqueueForBundle($stage, (int) $bundle->id, (int) $bundle->revision, $sealed ? 'publish' : 'prepare', []);
            DB::table('obfuscation_recovery_work')->where('id', $workId)->where('status', 'pending')->update(['due_at' => now()]);
            if (str_starts_with($bundle->reason ?? '', 'frontier_')) {
                DB::table('obfuscation_recovery_bundles')->where('id', $id)->update(['reason' => null, 'next_action_at' => now()]);

                return 'frontier_candidates_advancing';
            }

            return 'frontier_evidence_usable';
        }
        if (in_array($assessment, ['waiting_quiet_interval', 'invalid_candidate_envelope', 'conflicting_posting_frontier'], true)) {
            return 'frontier_rebuild_unresolved';
        }
        if ($sealed && ! (new RecoveryFrontierMembers)->advance(DB::connection(), $bundle)) {
            return $this->reason($bundle, 'frontier_members_pending');
        }
        $context = (new RecoverySettlement)->context($bundle->source_epoch, (int) $bundle->groups_id, (int) $bundle->capture_generation,
            $envelope['first_article'], $envelope['last_article'], $envelope['first_postdate'], $envelope['last_postdate'], $sealed);
        $unresolved = 'frontier_boundary_unresolved';
        foreach (['middle', 'left', 'right'] as $side) {
            $progress = (new RecoveryIdentity)->digest(['frontier-progress', (string) $id, (string) $bundle->revision, $side]);
            $initial = $side === 'right' ? $envelope['last_article'] + 1 : ($side === 'left' ? $envelope['first_article'] - 1 : $envelope['first_article']);
            DB::table('obfuscation_recovery_frontier_progress')->insertOrIgnore(['scope' => $progress, 'cursor' => max(0, $initial)]);
            $position = DB::table('obfuscation_recovery_frontier_progress')->where('scope', $progress)->first();
            $cursor = (int) $position->cursor;
            if ($cursor < 1 || ($side === 'middle' && $cursor > $envelope['last_article'])
                || ($side === 'left' && $context['left'] !== null && $cursor < $context['left'])
                || ($side === 'right' && $context['right'] !== null && $cursor > $context['right'])) {
                continue;
            }
            if ($position->due_at !== null && $position->due_at > now()) {
                $unresolved = 'frontier_range_exhausted';

                continue;
            }
            $windows = DB::table('obfuscation_recovery_scan_windows')->where('groups_id', $bundle->groups_id)
                ->where('source_epoch', $bundle->source_epoch)->where('capture_generation', $bundle->capture_generation)
                ->when(! $sealed, fn ($query) => $query->where('expires_at', '>', now()));
            $window = RecoveryFrontierWindows::overlapping(DB::connection(), $bundle, $cursor, $cursor, $sealed)
                ->orderByDesc('requested_first')->first();
            if ($window === null) {
                $window = $side === 'left' ? (clone $windows)->where('requested_first', '<', $cursor)->orderByDesc('requested_first')->first()
                    : (clone $windows)->where('requested_first', '>', $cursor)->orderBy('requested_first')->first();
            }
            if ($window === null
                || ($side === 'left' && $context['left'] !== null && (int) $window->requested_last < $context['left'])
                || ($side === 'right' && $context['right'] !== null && (int) $window->requested_first > $context['right'])) {
                continue;
            }
            $position = max((int) $window->requested_first, min($cursor, (int) $window->requested_last));
            $partition = intdiv($position - 1, 20000) * 20000 + 1;
            $interval = (new RecoveryFrontierEvidence)->interval(DB::connection(), $bundle, $position, $sealed);
            if ($interval === null) {
                $unresolved = 'frontier_boundary_unavailable';

                continue;
            }
            $first = $interval['first'];
            $last = $interval['last'];
            $window->expires_at = $interval['expires_at'];
            $scope = RecoveryPositiveCoverage::scope($bundle->source_epoch, (int) $bundle->groups_id, (int) $bundle->capture_generation);
            if (! (new RecoveryFrontierEvidence)->retainedHead(DB::connection(), $bundle, $first, $last, $sealed)) {
                $unresolved = 'frontier_boundary_unavailable';

                continue;
            }
            if ((new RecoveryFrontierEvidence)->answer(DB::connection(), $scope, $first, $last, $envelope) === 'examined') {
                (new RecoveryFrontiers)->replaceLegacy(DB::connection(), $scope, $first, $last);
                if (! RecoveryFrontierConflicts::overlapping(DB::connection(), $scope, $first, $last, ['unknown', 'ordering'])->exists()) {
                    DB::table('obfuscation_recovery_frontier_progress')->where('scope', $progress)
                        ->update(['cursor' => $side === 'left' ? $first - 1 : $last + 1]);
                }

                continue;
            }

            $outcome = $this->queue($bundle, (int) $control->generation, $window, $first, $last, $partition, $envelope);
            if ($outcome !== 'frontier_rebuild_unresolved') {
                return $outcome;
            }
            $unresolved = 'frontier_range_exhausted';
            DB::table('obfuscation_recovery_frontier_progress')->where('scope', $progress)->update(['due_at' => now()->addMinutes(5)]);
        }

        $this->reason($bundle, $unresolved);

        return $unresolved === 'frontier_range_exhausted' ? 'frontier_required_exhausted' : 'frontier_rebuild_unresolved';
    }

    /** @param array{first_article:int,last_article:int,first_postdate:string,last_postdate:string,changed_at:string} $envelope */
    private function queue(object $bundle, int $generation, object $window, int $first, int $last, int $partition, array $envelope): string
    {
        $identity = new RecoveryIdentity;
        $budgetOwner = $identity->digest(['frontier-range', $bundle->source_epoch, (string) $bundle->groups_id, (string) RecoveryFrontiers::VERSION, (string) $partition]);
        $context = (new RecoverySettlement)->context($bundle->source_epoch, (int) $bundle->groups_id, (int) $bundle->capture_generation,
            $envelope['first_article'], $envelope['last_article'], $envelope['first_postdate'], $envelope['last_postdate'], $this->sealed($bundle));
        $pending = DB::table('obfuscation_recovery_frontier_requests')->where('budget_owner', $budgetOwner)->where('capture_generation', $generation)
            ->where('outcome', 'pending')->where('expires_at', '>', now())->where('requested_first', '<=', $last)->where('requested_last', '>=', $first)
            ->when($context['left'] !== null, fn ($query) => $query->where('requested_last', '>=', $context['left']))
            ->when($context['right'] !== null, fn ($query) => $query->where('requested_first', '<=', $context['right']))
            ->orderBy('id')->first();
        if ($pending !== null && (new RecoveryFrontierEvidence)->retainedHead(DB::connection(), $bundle,
            (int) $pending->requested_first, (int) $pending->requested_last, $this->sealed($bundle))) {
            $first = (int) $pending->requested_first;
            $last = (int) $pending->requested_last;
            $window->expires_at = min($window->expires_at, $pending->expires_at);
        }
        $owner = $identity->digest([$budgetOwner, (string) $generation, (string) $first, (string) $last]);
        DB::table('obfuscation_recovery_bundles')->insertOrIgnore([
            'owner_digest' => $owner, 'kind' => 'frontier', 'groups_id' => $bundle->groups_id, 'profile' => $bundle->profile,
            'source_epoch' => $bundle->source_epoch, 'capture_generation' => $generation, 'revision' => 1,
            'state' => 'frontier_pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ownerId = (int) DB::table('obfuscation_recovery_bundles')->where('owner_digest', $owner)->value('id');
        DB::table('obfuscation_recovery_frontier_requests')->insertOrIgnore([
            'bundle_id' => $ownerId, 'budget_owner' => $budgetOwner, 'groups_id' => $bundle->groups_id, 'source_epoch' => $bundle->source_epoch,
            'capture_generation' => $generation, 'evidence_version' => RecoveryFrontiers::VERSION, 'requested_first' => $first,
            'requested_last' => $last, 'expires_at' => $this->sealed($bundle) ? now()->addHour() : $window->expires_at,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $request = DB::table('obfuscation_recovery_frontier_requests')->where('bundle_id', $ownerId)->first();
        $ownerBundle = DB::table('obfuscation_recovery_bundles')->where('id', $ownerId)->first();
        $validTargets = (new RecoveryFrontierTargets)->authorized(DB::connection(), $ownerBundle,
            ['first' => $first, 'last' => $last, 'version' => RecoveryFrontiers::VERSION], true) ?? [];
        DB::table('obfuscation_recovery_frontier_targets')->where('request_id', $request->id)
            ->whereNotIn('id', array_column($validTargets, 'id'))->delete();
        if (count($validTargets) >= 16 && ! in_array((int) $bundle->id, array_map(intval(...), array_column($validTargets, 'bundle_id')), true)) {
            return $this->reason($bundle, 'frontier_targets_pending');
        }
        DB::table('obfuscation_recovery_frontier_targets')->insertOrIgnore([
            'request_id' => $request->id, 'bundle_id' => $bundle->id, 'revision' => $bundle->revision,
            'plan_digest' => $this->sealed($bundle) ? hash('sha256', $bundle->sealed_plan) : null,
            'capture_generation' => $bundle->capture_generation, 'first_article' => $first, 'last_article' => $last,
            'envelope' => json_encode($envelope, JSON_THROW_ON_ERROR),
        ]);
        if (in_array($request->outcome, ['frontier_limit_reached', 'frontier_unresolved', 'expired_unresolved'], true)) {
            (new RecoveryFrontierAllowance)->grant($request, $ownerBundle);
            if (! app(RecoveryBudget::class)->frontierAvailable($request)) {
                return $this->reason($bundle, 'frontier_range_exhausted');
            }
        }
        $workId = app(RecoveryWork::class)->enqueueForBundle(RecoveryStage::Download, $ownerId, 1, self::PURPOSE,
            ['first' => $first, 'last' => $last, 'version' => RecoveryFrontiers::VERSION]);
        DB::table('obfuscation_recovery_work')->where('id', $workId)->whereIn('status', ['completed', 'obsolete'])
            ->update(['status' => 'pending', 'due_at' => now(), 'result' => null]);
        DB::table('obfuscation_recovery_frontier_requests')->where('id', $request->id)->update(['outcome' => 'pending', 'expires_at' => $this->sealed($bundle) ? now()->addHour() : $window->expires_at]);
        DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->update(['reason' => 'frontier_rebuild_pending']);

        return 'frontier_rebuild_pending';
    }

    public function sealed(object $bundle): bool
    {
        return $bundle->manifest_verified_at !== null && $bundle->sealed_plan !== null
            && in_array($bundle->state, ['ready', 'publishing', 'published'], true);
    }

    /** @return array{first_article:int,last_article:int,first_postdate:string,last_postdate:string,changed_at:string}|null */
    public function envelope(object $bundle): ?array
    {
        if ($this->sealed($bundle)) {
            return json_decode($bundle->coverage_evidence ?? 'null', true, flags: JSON_THROW_ON_ERROR);
        }
        $ids = json_decode($bundle->candidate_runs ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        if ($ids === [] || count($ids) > 256 || $bundle->membership_changed_at === null) {
            return null;
        }
        $runs = DB::table('obfuscation_recovery_runs')->whereIn('id', $ids)->where('active', true)->pluck('summary')
            ->map(static fn (string $summary): array => json_decode($summary, true, flags: JSON_THROW_ON_ERROR))->all();
        if (count($runs) !== count($ids)) {
            return null;
        }

        return ['first_article' => min(array_column($runs, 'first_article')), 'last_article' => max(array_column($runs, 'last_article')),
            'first_postdate' => min(array_column($runs, 'first_postdate')), 'last_postdate' => max(array_column($runs, 'last_postdate')),
            'changed_at' => $bundle->membership_changed_at];
    }

    private function reason(object $bundle, string $reason): string
    {
        DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->update(['reason' => $reason]);

        return 'frontier_rebuild_unresolved';
    }
}
