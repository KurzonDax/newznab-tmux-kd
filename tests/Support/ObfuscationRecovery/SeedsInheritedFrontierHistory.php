<?php

declare(strict_types=1);

namespace Tests\Support\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryFrontierAllowance;
use App\Services\ObfuscationRecovery\RecoveryFrontierRebuild;
use App\Services\ObfuscationRecovery\RecoveryFrontiers;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use App\Services\ObfuscationRecovery\RecoveryPositiveCoverage;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait SeedsInheritedFrontierHistory
{
    /** @return array{owner:string,attempts:string,policy:object,requests:list<int>} */
    private function seedInheritedHistory(object $bundle, int $tile, string $history): array
    {
        $present = now()->copy();
        $this->travelTo($present->copy()->subMinutes(20));
        Schema::drop('obfuscation_recovery_frontier_policy');
        $identity = new RecoveryIdentity;
        $owner = $identity->digest(['frontier-range', $bundle->source_epoch, (string) $bundle->groups_id,
            (string) RecoveryFrontiers::VERSION, (string) $tile]);
        $budget = DB::table('obfuscation_recovery_budgets')->insertGetId([
            'owner_digest' => $identity->digest(['budget', $owner]), 'purpose' => RecoveryFrontierRebuild::PURPOSE,
            'debited_bytes' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $intervals = $history === 'r1' ? [[$tile, $tile + 15403]] : [[$tile, $tile + 15403], [$tile + 15404, $tile + 16999]];
        $requests = [];
        foreach ($intervals as [$first, $last]) {
            $ownerId = DB::table('obfuscation_recovery_bundles')->insertGetId([
                'owner_digest' => $identity->digest([$owner, (string) $bundle->capture_generation, (string) $first, (string) $last]),
                'kind' => 'frontier', 'groups_id' => $bundle->groups_id, 'profile' => $bundle->profile,
                'source_epoch' => $bundle->source_epoch, 'capture_generation' => $bundle->capture_generation,
                'state' => 'frontier_pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $request = DB::table('obfuscation_recovery_frontier_requests')->insertGetId([
                'bundle_id' => $ownerId, 'budget_owner' => $owner, 'groups_id' => $bundle->groups_id,
                'source_epoch' => $bundle->source_epoch, 'capture_generation' => $bundle->capture_generation,
                'evidence_version' => RecoveryFrontiers::VERSION, 'requested_first' => $first, 'requested_last' => $last,
                'expires_at' => $present->copy()->addDay(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('obfuscation_recovery_frontier_targets')->insert([
                'request_id' => $request, 'bundle_id' => $bundle->id, 'revision' => $bundle->revision,
                'capture_generation' => $bundle->capture_generation, 'first_article' => $first, 'last_article' => $last,
                'envelope' => json_encode((new RecoveryFrontierRebuild)->envelope($bundle), JSON_THROW_ON_ERROR),
            ]);
            app(RecoveryWork::class)->enqueueForBundle(RecoveryStage::Download, $ownerId, 1, RecoveryFrontierRebuild::PURPOSE,
                ['first' => $first, 'last' => $last, 'version' => RecoveryFrontiers::VERSION]);
            $requests[] = $request;
        }
        $this->travel(1)->seconds();
        if (in_array($history, ['r1', 'r2', 'r2_pending'], true)) {
            $this->installHistoricalPartial($requests[0], $budget, false);
        }
        if ($history === 'r1') {
            $this->installHistoricalPartial($requests[0], $budget, false);
        }
        (require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php'))->up();
        $policy = DB::table('obfuscation_recovery_frontier_policy')->first();
        $this->travel(1)->seconds();
        if ($history === 'r2') {
            $this->installHistoricalPartial($requests[1], $budget, true);
        } elseif ($history === 'r3') {
            $this->installHistoricalPartial($requests[0], $budget, false);
            $this->installHistoricalPartial($requests[1], $budget, true);
        }
        $this->travelTo($present);

        return ['owner' => $owner, 'attempts' => DB::table('obfuscation_recovery_attempts')->orderBy('id')->get()->toJson(),
            'policy' => $policy, 'requests' => $requests];
    }

    private function installHistoricalPartial(int $requestId, int $budget, bool $logical): void
    {
        $request = DB::table('obfuscation_recovery_frontier_requests')->where('id', $requestId)->first();
        $digest = (new RecoveryIdentity)->digest(['request', $logical ? (new RecoveryFrontierAllowance)->logicalRequest($request) : $request->budget_owner]);
        $ordinal = DB::table('obfuscation_recovery_attempts')->where('budget_id', $budget)->where('request_digest', $digest)->count() + 1;
        DB::table('obfuscation_recovery_attempts')->insert([
            'budget_id' => $budget, 'request_digest' => $digest, 'physical_attempt' => $ordinal,
            'token' => (string) Str::uuid(), 'reserved_bytes' => 33554432, 'debited_bytes' => 33554432,
            'outcome' => 'success', 'settled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('obfuscation_recovery_budgets')->where('id', $budget)->increment('debited_bytes', 33554432);
        $this->travel(1)->seconds();
        (new RecoveryFrontiers)->saveRange(DB::connection(), RecoveryPositiveCoverage::scope($request->source_epoch,
            (int) $request->groups_id, (int) $request->capture_generation), (int) $request->requested_first, (int) $request->requested_last, [], true, true);
        DB::table('obfuscation_recovery_frontier_requests')->where('id', $requestId)->update(['outcome' => 'frontier_rebuilt', 'updated_at' => now()]);
        DB::table('obfuscation_recovery_bundles')->where('id', $request->bundle_id)->update(['state' => 'frontier_complete']);
        DB::table('obfuscation_recovery_work')->where('bundle_id', $request->bundle_id)->update(['status' => 'completed', 'result' => 'frontier_rebuilt']);
        $this->travel(1)->seconds();
    }
}
