<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;

final class RecoveryFrontierAllowance
{
    /** @param list<string> $members
     * @return array{requests:int,bytes:int}
     */
    public function limits(array $members): array
    {
        $grant = DB::table('obfuscation_recovery_frontier_allowances')->whereIn('owner_digest', $members)->first();

        return $grant === null ? ['requests' => 2, 'bytes' => 67108864]
            : ['requests' => 4, 'bytes' => min(134217728, (int) $grant->normal_bytes + 67108864)];
    }

    public function grant(object $request, object $owner): bool
    {
        $identity = new RecoveryIdentity;
        $owners = new RecoveryBudgetOwners($identity);
        $root = $owners->locked($request->budget_owner);
        $members = $owners->members($request->budget_owner);
        if (DB::table('obfuscation_recovery_frontier_allowances')->whereIn('owner_digest', $members)->exists()) {
            return false;
        }
        $payload = ['first' => (int) $request->requested_first, 'last' => (int) $request->requested_last, 'version' => RecoveryFrontiers::VERSION];
        $targets = new RecoveryFrontierTargets;
        if ($targets->authorized(DB::connection(), $owner, $payload, true) === null || $targets->sufficient(DB::connection(), $owner, $payload)) {
            return false;
        }
        $policy = DB::table('obfuscation_recovery_frontier_policy')->where('policy', 'connected-fragments-v1')->first();
        if ($policy === null) {
            return false;
        }
        $budgets = DB::table('obfuscation_recovery_budgets')->whereIn('owner_digest', $members)->where('purpose', RecoveryFrontierRebuild::PURPOSE)->get();
        $attempts = DB::table('obfuscation_recovery_attempts')->whereIn('budget_id', $budgets->pluck('id'))->orderBy('id')->limit(3)->get();
        if ($attempts->count() !== 2 || $attempts->contains(fn (object $attempt): bool => $attempt->outcome !== 'success'
            || $attempt->settled_at === null)) {
            return false;
        }
        $partition = intdiv((int) $request->requested_first - 1, 20000) * 20000 + 1;
        $requests = DB::table('obfuscation_recovery_frontier_requests')->where('source_epoch', $request->source_epoch)
            ->where('groups_id', $request->groups_id)->where('evidence_version', RecoveryFrontiers::VERSION)
            ->whereBetween('requested_first', [$partition, $partition + 19999])->orderBy('requested_first')->orderBy('id')->limit(33)->get();
        if ($requests->count() > 32) {
            return false;
        }
        $history = [];
        foreach ($attempts as $attempt) {
            $installation = DB::table('obfuscation_recovery_frontier_installs')->where('attempt_id', $attempt->id)->first();
            $matches = [];
            foreach ($requests as $fragment) {
                if ((int) $fragment->requested_last > $partition + 19999
                    || (int) $fragment->requested_last - (int) $fragment->requested_first >= 19999
                    || (int) $fragment->requested_last < (int) $fragment->requested_first
                    || ! in_array($identity->digest(['budget', $fragment->budget_owner]), $members, true)
                    || $fragment->outcome !== 'frontier_rebuilt' || $fragment->created_at > $attempt->created_at
                    || $fragment->updated_at < $attempt->settled_at || $fragment->updated_at > now()->format('Y-m-d H:i:s.u')) {
                    continue;
                }
                $cohort = $this->cohort($fragment, $policy);
                if ($cohort === null) {
                    continue;
                }
                $logical = $attempt->request_digest === $identity->digest(['request', $this->logicalRequest($fragment)]);
                $historical = $attempt->request_digest === $identity->digest(['request', $fragment->budget_owner]);
                if (! $logical && ! $historical) {
                    continue;
                }
                if ($installation !== null) {
                    if ((int) $installation->request_id !== (int) $fragment->id || $installation->installed_at === null
                        || $installation->evidence_digest === null || $installation->installed_at < $attempt->settled_at) {
                        continue;
                    }
                } else {
                    $scope = RecoveryPositiveCoverage::scope($fragment->source_epoch, (int) $fragment->groups_id, (int) $fragment->capture_generation);
                    if (! DB::table('obfuscation_recovery_frontier_ranges')->where('scope_digest', $scope)
                        ->where('evidence_version', $fragment->evidence_version)->where('head_observed', true)
                        ->where('first_article', $fragment->requested_first)->where('last_article', $fragment->requested_last)
                        ->where('observed_at', '>=', $attempt->settled_at)->where('observed_at', '<=', $fragment->updated_at)->exists()) {
                        continue;
                    }
                }
                $matches[] = ['request_id' => (int) $fragment->id, 'cohort_request_id' => $cohort, 'attempt_id' => (int) $attempt->id,
                    'first' => (int) $fragment->requested_first, 'last' => (int) $fragment->requested_last,
                    'completed_at' => $fragment->updated_at,
                    'reason' => $installation !== null ? 'attributed_installation' : ($logical ? 'logical_installed_history' : 'unique_owner_installed_history')];
            }
            if (count($matches) > 1) {
                $next = $attempts->first(fn (object $later): bool => (int) $later->id > (int) $attempt->id);
                $closed = $next === null ? [] : array_values(array_filter($matches,
                    static fn (array $match): bool => $match['reason'] === 'unique_owner_installed_history' && $match['completed_at'] < $next->created_at));
                if (count($closed) === 1) {
                    $closed[0]['reason'] = 'owner_install_before_next_attempt';
                    $matches = $closed;
                }
            }
            if (count($matches) !== 1) {
                return false;
            }
            $history[] = $matches[0];
        }
        DB::table('obfuscation_recovery_frontier_allowances')->insert([
            'owner_digest' => $root, 'normal_bytes' => (int) $budgets->sum('debited_bytes'),
            'history' => json_encode($history, JSON_THROW_ON_ERROR), 'granted_at' => now(),
        ]);

        return true;
    }

    private function cohort(object $request, object $policy): ?int
    {
        for ($depth = 0; $depth < 8; $depth++) {
            if ((int) $request->id <= (int) $policy->last_request_id && $request->created_at <= $policy->cutover_at) {
                return (int) $request->id;
            }
            $parents = DB::table('obfuscation_recovery_frontier_requests')->where('superseded_by', $request->id)
                ->where('source_epoch', $request->source_epoch)->where('groups_id', $request->groups_id)
                ->where('evidence_version', $request->evidence_version)->where('budget_owner', $request->budget_owner)->orderBy('id')->limit(2)->get();
            if ($parents->count() !== 1) {
                return null;
            }
            $request = $parents->first();
        }

        return null;
    }

    public function logicalRequest(object $request): string
    {
        return implode(':', ['frontier', $request->source_epoch, $request->groups_id, $request->evidence_version,
            $request->requested_first, $request->requested_last]);
    }
}
