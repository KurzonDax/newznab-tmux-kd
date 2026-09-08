<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RecoveryBundleRefresh
{
    public function __construct(private readonly RecoveryComponents $components) {}

    public function step(): ?int
    {
        $id = DB::table('obfuscation_recovery_runs')->where('bundle_dirty', true)->orderBy('updated_at')->orderBy('id')->value('id');

        return $id === null ? null : $this->refresh((int) $id);
    }

    public function refresh(int $runId): ?int
    {
        $seed = DB::table('obfuscation_recovery_runs')->where('id', $runId)->first();
        if ($seed === null) {
            return null;
        }

        return DB::transaction(function () use ($seed): ?int {
            $control = DB::table('obfuscation_recovery_controls')->where('scope', 'group:'.$seed->groups_id)->lockForUpdate()->first();
            $source = DB::table('obfuscation_recovery_controls')->where('scope', 'primary')->first();
            $seed = DB::table('obfuscation_recovery_runs')->where('id', $seed->id)->first();
            if ($seed === null) {
                return null;
            }
            if ($control === null || $source === null || (int) $control->generation !== (int) $seed->capture_generation || $source->epoch !== $seed->source_epoch) {
                DB::table('obfuscation_recovery_runs')->where('id', $seed->id)->update(['bundle_dirty' => false]);

                return null;
            }
            $structural = null;
            try {
                $runs = $this->components->forRun($seed);
            } catch (InvalidArgumentException $exception) {
                $structural = $exception->getMessage();
                $runs = [$seed];
            }
            if ($runs === []) {
                $this->removedRun($seed);

                return null;
            }
            $first = min(array_column($runs, 'start_ms'));
            $last = max(array_column($runs, 'end_ms'));
            $gap = $seed->profile === RecoveryAlgorithm::Media->value ? 30000 : 3000;
            $query = DB::table('obfuscation_recovery_bundles')->where('source_epoch', $seed->source_epoch)->where('groups_id', $seed->groups_id)
                ->where('profile', $seed->profile)->where('state', '!=', 'coalesced')
                ->whereBetween('start_ms', [max(0, $first - 21600000 - $gap), $last + $gap])->where('end_ms', '>=', max(0, $first - $gap));
            if ($seed->profile === RecoveryAlgorithm::Rar->value) {
                $query->where('key_digest', $seed->partition_value);
            }
            $owners = $query->orderBy('id')->limit(257)->lockForUpdate()->get();
            if ($owners->count() > 256) {
                throw new InvalidArgumentException('candidate_owner_cap');
            }
            $identity = new RecoveryIdentity;
            $snapshot = $identity->digest(array_map(static fn (object $run): string => $run->scope_digest.$run->membership_digest, $runs));
            $root = $owners->firstWhere('state', 'published') ?? $owners->first();
            if ($root !== null && ($root->state === 'published' || in_array($root->state, RecoveryOwnership::INACTIVE_STATES, true))) {
                if ($root->state === 'published' && $root->snapshot_digest !== $snapshot) {
                    DB::table('obfuscation_recovery_bundles')->where('id', $root->id)->update(['reason' => 'late_membership_conflict', 'updated_at' => now()]);
                }
                DB::table('obfuscation_recovery_runs')->whereIn('id', array_column($runs, 'id'))->update(['bundle_dirty' => false]);

                return (int) $root->id;
            }
            $changed = $root === null || $root->snapshot_digest !== $snapshot || (int) $root->capture_generation !== (int) $seed->capture_generation;
            $changedAt = max(array_map(static fn (object $run): string => json_decode($run->summary, true, flags: JSON_THROW_ON_ERROR)['membership_changed_at'], $runs));
            $revision = $root === null ? 1 : (int) $root->revision + ($changed && $root->snapshot_digest !== null ? 1 : 0);
            $data = ['profile' => $seed->profile, 'groups_id' => $seed->groups_id, 'source_epoch' => $seed->source_epoch,
                'capture_generation' => $seed->capture_generation, 'key_digest' => $seed->profile === RecoveryAlgorithm::Rar->value ? $seed->partition_value : null,
                'revision' => $revision, 'start_ms' => $first, 'end_ms' => $last, 'snapshot_digest' => $snapshot,
                'candidate_runs' => json_encode(array_column($runs, 'id'), JSON_THROW_ON_ERROR), 'updated_at' => now()];
            if ($changed) {
                $data += ['state' => $structural === null ? 'collecting' : 'unsupported', 'reason' => $structural,
                    'sealed_plan' => null, 'manifest_verified_at' => null, 'membership_changed_at' => $changedAt,
                    'next_action_at' => max(now()->format('Y-m-d H:i:s.u'), Carbon::parse($changedAt)->addMinutes(120)->format('Y-m-d H:i:s.u'))];
            }
            if ($owners->count() > 1) {
                $targets = [];
                foreach ($owners as $owner) {
                    $targets = [...$targets, ...json_decode($owner->construction_targets ?? '[]', true, flags: JSON_THROW_ON_ERROR)];
                }
                if ($targets !== []) {
                    try {
                        $data['construction_targets'] = json_encode(RecoveryConstructionTargets::combine(RecoveryAlgorithm::from($seed->profile), $targets), JSON_THROW_ON_ERROR);
                    } catch (InvalidArgumentException $exception) {
                        $data['state'] = 'unsupported';
                        $data['reason'] = $exception->getMessage();
                    }
                }
            }
            if ($root === null) {
                $id = DB::table('obfuscation_recovery_bundles')->insertGetId($data + [
                    'owner_digest' => $identity->digest(['candidate-owner', (string) Str::uuid()]), 'created_at' => now(),
                ]);
            } else {
                $id = (int) $root->id;
                DB::table('obfuscation_recovery_bundles')->where('id', $id)->update($data);
            }
            if ($owners->count() > 1) {
                (new RecoveryBudgetOwners($identity))->merge($owners->pluck('owner_digest')->all());
                $otherIds = $owners->where('id', '!=', $id)->pluck('id')->all();
                DB::table('obfuscation_recovery_bundles')->whereIn('id', $otherIds)->update([
                    'state' => 'coalesced', 'merged_into' => $id, 'sealed_plan' => null, 'manifest_verified_at' => null,
                    'claim_token' => null, 'claim_expires_at' => null, 'updated_at' => now(),
                ]);
            }
            if ($changed || $owners->count() > 1) {
                DB::table('obfuscation_recovery_work')->whereIn('bundle_id', [$id, ...$owners->pluck('id')->all()])
                    ->whereIn('status', ['pending', 'claimed'])->update([
                        'status' => 'obsolete', 'claim_token' => null, 'claim_expires_at' => null, 'updated_at' => now(),
                    ]);
            }
            DB::table('obfuscation_recovery_runs')->whereIn('id', array_column($runs, 'id'))->update(['bundle_dirty' => false]);
            if (($data['state'] ?? $root?->state) === 'collecting') {
                $workId = app(RecoveryWork::class)->enqueueForBundle(RecoveryStage::Discover, $id, $revision, 'prepare', []);
                if ($changed) {
                    DB::table('obfuscation_recovery_work')->where('id', $workId)->where('status', 'pending')
                        ->update(['due_at' => $data['next_action_at']]);
                }
            }

            return $id;
        }, 1);
    }

    private function removedRun(object $run): void
    {
        DB::table('obfuscation_recovery_runs')->where('id', $run->id)->update(['bundle_dirty' => false]);
        DB::table('obfuscation_recovery_runs')->where('source_epoch', $run->source_epoch)->where('groups_id', $run->groups_id)
            ->where('capture_generation', $run->capture_generation)->where('profile', $run->profile)->where('active', true)
            ->whereBetween('start_ms', [max(0, (int) $run->start_ms - 21630000), (int) $run->end_ms + 30000])
            ->where('end_ms', '>=', max(0, (int) $run->start_ms - 30000))->update(['bundle_dirty' => true]);
    }
}
