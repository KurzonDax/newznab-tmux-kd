<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecoveryRunRefresh
{
    public function __construct(private readonly RecoveryRunDiscovery $discovery) {}

    public function step(): ?int
    {
        $claim = $this->claim();
        if ($claim === null) {
            return null;
        }
        $run = $this->discovery->scan($claim);

        return $this->finish($claim, $run) ? (int) $claim->id : null;
    }

    public function claim(): ?object
    {
        $config = RecoveryConfig::fromSettings();
        if (! $config->enabled) {
            return null;
        }

        return DB::transaction(function () use ($config): ?object {
            $candidate = DB::table('obfuscation_recovery_dirty')->where('next_action_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('claim_token')->orWhere('claim_expires_at', '<=', now()))
                ->orderBy('next_action_at')->orderBy('id')->first();
            if ($candidate === null) {
                return null;
            }
            $control = DB::table('obfuscation_recovery_controls')->where('scope', 'group:'.$candidate->groups_id)->lockForUpdate()->first();
            $row = DB::table('obfuscation_recovery_dirty')->where('id', $candidate->id)
                ->where(fn ($query) => $query->whereNull('claim_token')->orWhere('claim_expires_at', '<=', now()))->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }
            if (! $this->current($row, $control)) {
                DB::table('obfuscation_recovery_dirty')->where('id', $row->id)->delete();

                return null;
            }
            $profile = RecoveryAlgorithm::tryFrom($row->profile);
            $selection = DB::table('usenet_groups')->where('id', $row->groups_id)->value('obfuscation_recovery_profile');
            if ($profile === null || ! $config->admits($selection, $profile->selection())) {
                DB::table('obfuscation_recovery_dirty')->where('id', $row->id)->update(['next_action_at' => now()->addHour()]);

                return null;
            }
            $row->claim_token = (string) Str::uuid();
            $row->claim_expires_at = now()->addSeconds(90)->format('Y-m-d H:i:s.u');
            DB::table('obfuscation_recovery_dirty')->where('id', $row->id)
                ->update(['claim_token' => $row->claim_token, 'claim_expires_at' => $row->claim_expires_at]);

            return $row;
        }, 1);
    }

    /** @param array<string,mixed>|null $run */
    public function finish(object $claim, ?array $run): bool
    {
        return DB::transaction(function () use ($claim, $run): bool {
            $control = DB::table('obfuscation_recovery_controls')->where('scope', 'group:'.$claim->groups_id)->lockForUpdate()->first();
            $dirty = DB::table('obfuscation_recovery_dirty')->where('id', $claim->id)->where('claim_token', $claim->claim_token)
                ->where('claim_expires_at', '>', now())->lockForUpdate()->first();
            if ($dirty === null) {
                return false;
            }
            if ((int) $dirty->version !== (int) $claim->version || ! $this->current($dirty, $control)) {
                DB::table('obfuscation_recovery_dirty')->where('id', $dirty->id)
                    ->update(['claim_token' => null, 'claim_expires_at' => null]);

                return false;
            }
            if ($run !== null) {
                $identity = (new RecoveryIdentity)->digest(['run', $dirty->scope_digest, $run['first_message_id']]);
                DB::table('obfuscation_recovery_runs')->where('scope_digest', $dirty->scope_digest)->where('active', true)
                    ->where('start_ms', '<=', $run['end_ms'])->where('end_ms', '>=', $run['start_ms'])
                    ->where('run_identity', '!=', $identity)->update(['active' => false, 'bundle_dirty' => true, 'updated_at' => now()]);
                $previous = DB::table('obfuscation_recovery_runs')->where('run_identity', $identity)->first();
                $changed = $previous === null || ! $previous->active || $previous->membership_digest !== $run['membership_digest'];
                $run['membership_changed_at'] = $changed
                    ? max($run['membership_changed_at'], $dirty->membership_changed_at)
                    : json_decode($previous->summary, true, flags: JSON_THROW_ON_ERROR)['membership_changed_at'];
                DB::table('obfuscation_recovery_runs')->upsert([
                    'run_identity' => $identity, 'scope_digest' => $dirty->scope_digest, 'source_epoch' => $dirty->source_epoch,
                    'groups_id' => $dirty->groups_id, 'capture_generation' => $dirty->capture_generation,
                    'profile' => $dirty->profile, 'partition_value' => $dirty->partition_value,
                    'start_ms' => $run['start_ms'], 'end_ms' => $run['end_ms'], 'observed_count' => $run['observed_count'],
                    'state' => $run['state'], 'membership_digest' => $run['membership_digest'],
                    'summary' => json_encode($run, JSON_THROW_ON_ERROR), 'active' => true,
                    'bundle_dirty' => $changed || (bool) $previous?->bundle_dirty,
                    'oldest_observed_at' => $run['oldest_observed_at'], 'created_at' => now(), 'updated_at' => now(),
                ], ['run_identity'], ['start_ms', 'end_ms', 'observed_count', 'state', 'membership_digest', 'summary', 'active', 'bundle_dirty', 'oldest_observed_at', 'updated_at']);
            }
            if ($run === null) {
                DB::table('obfuscation_recovery_runs')->where('scope_digest', $dirty->scope_digest)->where('active', true)
                    ->where('start_ms', '<=', $dirty->last_ms)->where('end_ms', '>=', $dirty->first_ms)
                    ->update(['active' => false, 'bundle_dirty' => true, 'state' => 'expired_unresolved', 'updated_at' => now()]);
            }
            if ($run === null || $run['end_ms'] >= (int) $dirty->last_ms) {
                DB::table('obfuscation_recovery_dirty')->where('id', $dirty->id)->delete();
            } else {
                DB::table('obfuscation_recovery_dirty')->where('id', $dirty->id)->update([
                    'first_ms' => $run['end_ms'] + 1, 'claim_token' => null, 'claim_expires_at' => null, 'next_action_at' => now(),
                ]);
            }

            return true;
        }, 1);
    }

    private function current(object $scope, ?object $control): bool
    {
        $source = DB::table('obfuscation_recovery_controls')->where('scope', 'primary')->first();

        return $control !== null && $source !== null && (int) $control->generation === (int) $scope->capture_generation
            && $source->epoch === $scope->source_epoch;
    }
}
