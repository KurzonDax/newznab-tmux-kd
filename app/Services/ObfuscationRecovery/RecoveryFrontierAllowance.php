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
            || $attempt->settled_at === null || $attempt->settled_at > $policy->cutover_at || (int) $attempt->id > (int) $policy->last_attempt_id)) {
            return false;
        }
        $requests = DB::table('obfuscation_recovery_frontier_requests')->where('budget_owner', $request->budget_owner)
            ->where('id', '<=', $policy->last_request_id)->where('updated_at', '<=', $policy->cutover_at)
            ->where('outcome', 'frontier_rebuilt')->orderBy('requested_first')->limit(3)->get();
        if ($requests->count() !== 2) {
            return false;
        }
        $partition = intdiv((int) $request->requested_first - 1, 20000) * 20000 + 1;
        $history = [];
        $used = [];
        $end = 0;
        foreach ($requests as $fragment) {
            if ((int) $fragment->requested_first < $partition || (int) $fragment->requested_last > $partition + 19999
                || (int) $fragment->requested_first <= $end || (int) $fragment->requested_last - (int) $fragment->requested_first >= 19999
                || $fragment->source_epoch !== $request->source_epoch || (int) $fragment->groups_id !== (int) $request->groups_id
                || (int) $fragment->evidence_version !== RecoveryFrontiers::VERSION) {
                return false;
            }
            $attempt = $attempts->first(fn (object $attempt): bool => ! in_array($attempt->id, $used, true)
                && $attempt->created_at >= $fragment->created_at && $attempt->settled_at <= $fragment->updated_at
                && $attempt->request_digest === $identity->digest(['request', $request->budget_owner]));
            if ($attempt === null) {
                return false;
            }
            $used[] = $attempt->id;
            $history[] = ['request_id' => (int) $fragment->id, 'attempt_id' => (int) $attempt->id,
                'first' => (int) $fragment->requested_first, 'last' => (int) $fragment->requested_last];
            $end = (int) $fragment->requested_last;
        }
        DB::table('obfuscation_recovery_frontier_allowances')->insert([
            'owner_digest' => $root, 'normal_bytes' => (int) $budgets->sum('debited_bytes'),
            'history' => json_encode($history, JSON_THROW_ON_ERROR), 'granted_at' => now(),
        ]);

        return true;
    }

    public function logicalRequest(object $request): string
    {
        return implode(':', ['frontier', $request->source_epoch, $request->groups_id, $request->evidence_version,
            $request->requested_first, $request->requested_last]);
    }
}
