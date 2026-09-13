<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RecoveryWork
{
    public function __construct(private readonly RecoveryIdentity $identity) {}

    /** @param array<string, mixed> $payload */
    public function enqueue(RecoveryStage $stage, string $owner, int $revision, string $purpose, array $payload): int
    {
        if ($revision < 1 || strlen($purpose) > 40) {
            throw new InvalidArgumentException('invalid_work_revision');
        }
        $digest = $this->identity->digest(['work-owner', $owner]);

        return DB::transaction(function () use ($stage, $digest, $revision, $purpose, $payload): int {
            DB::table('obfuscation_recovery_bundles')->upsert([[
                'owner_digest' => $digest, 'revision' => $revision, 'created_at' => now(), 'updated_at' => now(),
            ]], ['owner_digest'], ['updated_at']);
            $bundleId = (int) DB::table('obfuscation_recovery_bundles')->where('owner_digest', $digest)->value('id');

            return $this->enqueueForBundle($stage, $bundleId, $revision, $purpose, $payload);
        }, 1);
    }

    /** @param array<string,mixed> $payload */
    public function enqueueForBundle(RecoveryStage $stage, int $bundleId, int $revision, string $purpose, array $payload): int
    {
        if ($bundleId < 1 || $revision < 1 || $purpose === '' || strlen($purpose) > 40) {
            throw new InvalidArgumentException('invalid_work_revision');
        }

        return DB::transaction(function () use ($stage, $bundleId, $revision, $purpose, $payload): int {
            $bundle = DB::table('obfuscation_recovery_bundles')->where('id', $bundleId)->lockForUpdate()->first();
            if ($bundle === null || (int) $bundle->revision !== $revision) {
                throw new InvalidArgumentException('obsolete_work_revision');
            }
            if (in_array($bundle->state, RecoveryOwnership::INACTIVE_STATES, true)) {
                throw new InvalidArgumentException('inactive_work_owner');
            }
            $lane = match ($purpose) {
                'enrichment' => 'enrichment',
                'gap', RecoveryFrontierRebuild::PURPOSE => 'gap',
                default => 'construction',
            };
            $scope = $this->identity->digest([$stage->value, (string) $bundle->groups_id, (string) $bundle->profile, $lane]);
            DB::table('obfuscation_recovery_dispatch')->insertOrIgnore([
                'scope_digest' => $scope, 'stage' => $stage->value,
            ]);
            $request = $this->identity->digest([$bundle->owner_digest, $stage->value, $purpose, json_encode($payload, JSON_THROW_ON_ERROR)]);
            DB::table('obfuscation_recovery_work')->insertOrIgnore([
                'bundle_id' => $bundle->id, 'revision' => $revision, 'stage' => $stage->value,
                'purpose' => $purpose, 'dispatch_scope' => $scope, 'request_digest' => $request, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'due_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return (int) DB::table('obfuscation_recovery_work')->where('request_digest', $request)->where('revision', $revision)->value('id');
        }, 1);
    }

    public function claim(RecoveryStage $stage): ?RecoveryWorkClaim
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $contended = false;
            $claim = $this->claimOnce($stage, $contended);
            if ($claim !== null || ! $contended) {
                return $claim;
            }
            usleep(20000);
        }

        return null;
    }

    private function claimOnce(RecoveryStage $stage, bool &$contended): ?RecoveryWorkClaim
    {
        return DB::transaction(function () use ($stage, &$contended): ?RecoveryWorkClaim {
            $candidates = DB::table('obfuscation_recovery_work as work')
                ->join('obfuscation_recovery_bundles as bundle', 'bundle.id', '=', 'work.bundle_id')
                ->join('obfuscation_recovery_dispatch as dispatch', 'dispatch.scope_digest', '=', 'work.dispatch_scope')
                ->where('work.stage', $stage->value)->where('work.status', 'pending')->where('work.due_at', '<=', now()->format('Y-m-d H:i:s.u'))
                ->whereColumn('work.revision', 'bundle.revision')->whereNotIn('bundle.state', RecoveryOwnership::INACTIVE_STATES)
                ->orderBy('dispatch.last_dispatched_at')->orderBy('work.due_at')->orderBy('work.id')->limit(10)
                ->get(['work.id', 'work.bundle_id', 'work.dispatch_scope']);
            $lock = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? 'for update skip locked' : true;
            $row = null;
            foreach ($candidates as $candidate) {
                $bundle = DB::table('obfuscation_recovery_bundles')->where('id', $candidate->bundle_id)->lock($lock)->first();
                if ($bundle === null || in_array($bundle->state, RecoveryOwnership::INACTIVE_STATES, true)) {
                    continue;
                }
                if (DB::table('obfuscation_recovery_dispatch')->where('scope_digest', $candidate->dispatch_scope)->lock($lock)->first() === null) {
                    continue;
                }
                $row = DB::table('obfuscation_recovery_work')->where('id', $candidate->id)->where('status', 'pending')
                    ->where('revision', $bundle->revision)->where('due_at', '<=', now()->format('Y-m-d H:i:s.u'))->lock($lock)->first();
                if ($row !== null && ! (new RecoveryOwnership)->current($bundle, $stage, $row->purpose,
                    json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR))) {
                    DB::table('obfuscation_recovery_work')->where('id', $row->id)->update([
                        'status' => 'obsolete', 'result' => 'capture_scope_changed', 'updated_at' => now(),
                    ]);
                    $row = null;
                }
                if ($row !== null) {
                    break;
                }
            }
            if ($row === null) {
                $contended = $candidates->isNotEmpty();

                return null;
            }
            DB::table('obfuscation_recovery_dispatch')->where('scope_digest', $row->dispatch_scope)
                ->update(['last_dispatched_at' => now()]);
            $token = (string) Str::uuid();
            $owner = RecoveryProcess::current();
            DB::table('obfuscation_recovery_work')->where('id', $row->id)->update([
                'status' => 'claimed', 'claim_token' => $token,
                'claim_owner_host' => $owner->host, 'claim_owner_pid' => $owner->pid, 'claim_owner_started' => $owner->started,
                'claim_expires_at' => now()->addSeconds(90), 'reclaim_after' => null, 'updated_at' => now(),
            ]);

            return new RecoveryWorkClaim((int) $row->id, (int) $row->bundle_id, (int) $row->revision,
                $stage, $row->purpose, $token, json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR));
        }, 1);
    }

    public function heartbeat(RecoveryWorkClaim $claim): bool
    {
        return DB::transaction(function () use ($claim): bool {
            if ((new RecoveryOwnership)->locked($claim) === null) {
                return false;
            }
            $query = $this->owned($claim);
            $changed = $query->update(['claim_expires_at' => now()->addSeconds(90), 'updated_at' => now()]);

            return $changed === 1 || $query->exists();
        }, 1);
    }

    public function complete(RecoveryWorkClaim $claim, string $result = 'completed'): bool
    {
        return DB::transaction(function () use ($claim, $result): bool {
            if ((new RecoveryOwnership)->locked($claim) === null) {
                return false;
            }

            $completed = $this->owned($claim)->update([
                'status' => 'completed', 'result' => $result, 'claim_token' => null, 'claim_expires_at' => null, 'updated_at' => now(),
            ]) === 1;
            if ($completed && $claim->stage === RecoveryStage::Download) {
                DB::table('obfuscation_recovery_work')->where('bundle_id', $claim->bundleId)->where('revision', $claim->revision)
                    ->where('stage', RecoveryStage::Discover->value)->where('status', 'pending')->update(['due_at' => now(), 'updated_at' => now()]);
            }

            return $completed;
        }, 1);
    }

    public function defer(RecoveryWorkClaim $claim, int $seconds = 60): bool
    {
        if ($seconds < 1 || $seconds > 3600) {
            throw new InvalidArgumentException('invalid_work_backoff');
        }

        return DB::transaction(function () use ($claim, $seconds): bool {
            if ((new RecoveryOwnership)->locked($claim) === null) {
                return false;
            }

            return $this->owned($claim)->update(['status' => 'pending', 'due_at' => now()->addSeconds($seconds),
                'claim_token' => null, 'claim_expires_at' => null, 'updated_at' => now()]) === 1;
        }, 1);
    }

    public function invalidate(string $owner, int $revision): void
    {
        DB::transaction(function () use ($owner, $revision): void {
            $bundle = DB::table('obfuscation_recovery_bundles')
                ->where('owner_digest', $this->identity->digest(['work-owner', $owner]))->lockForUpdate()->first();
            if ($bundle === null || $revision <= (int) $bundle->revision) {
                return;
            }
            DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->update([
                'revision' => $revision, 'sealed_plan' => null, 'manifest_verified_at' => null, 'snapshot_digest' => null,
                'state' => 'collecting', 'updated_at' => now(),
            ]);
            DB::table('obfuscation_recovery_work')->where('bundle_id', $bundle->id)->where('revision', '<', $revision)
                ->update(['status' => 'obsolete', 'claim_token' => null, 'claim_expires_at' => null, 'updated_at' => now()]);
        }, 1);
    }

    public function reclaimExpired(): int
    {
        $count = 0;
        $expired = DB::table('obfuscation_recovery_work')->where('status', 'claimed')->where('claim_expires_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('reclaim_after')->orWhere('reclaim_after', '<=', now()))
            ->orderBy('reclaim_after')->orderBy('id')->limit(10)->get();
        foreach ($expired as $row) {
            if ($row->claim_owner_host === null || $row->claim_owner_pid === null || $row->claim_owner_started === null
                || ! (new RecoveryProcess($row->claim_owner_host, (int) $row->claim_owner_pid, $row->claim_owner_started))->provenDead()) {
                DB::table('obfuscation_recovery_work')->where('id', $row->id)->where('status', 'claimed')
                    ->where('claim_token', $row->claim_token)->where('claim_expires_at', '<=', now())
                    ->update(['reclaim_after' => now()->addMinute()]);

                continue;
            }
            $count += DB::transaction(function () use ($row): int {
                $bundle = DB::table('obfuscation_recovery_bundles')->where('id', $row->bundle_id)->lockForUpdate()->first();
                $obsolete = $bundle === null || (int) $bundle->revision !== (int) $row->revision || in_array($bundle->state, RecoveryOwnership::INACTIVE_STATES, true)
                    || ! (new RecoveryOwnership)->current($bundle, RecoveryStage::from($row->stage), $row->purpose,
                        json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR));

                return DB::table('obfuscation_recovery_work')->where('id', $row->id)->where('status', 'claimed')->where('claim_token', $row->claim_token)
                    ->where('claim_expires_at', '<=', now())->update([
                        'status' => $obsolete ? 'obsolete' : 'pending', 'due_at' => now(), 'claim_token' => null, 'claim_expires_at' => null,
                        'claim_owner_host' => null, 'claim_owner_pid' => null, 'claim_owner_started' => null, 'updated_at' => now(),
                    ]);
            }, 1);
        }

        return $count;
    }

    private function owned(RecoveryWorkClaim $claim): Builder
    {
        return DB::table('obfuscation_recovery_work')->where('id', $claim->id)->where('bundle_id', $claim->bundleId)
            ->where('revision', $claim->revision)->where('status', 'claimed')->where('claim_token', $claim->token)
            ->where('claim_expires_at', '>', now());
    }
}
