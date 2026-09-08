<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecoverySlots
{
    public function acquire(RecoveryConfig $config): ?RecoverySlot
    {
        if (! $config->enabled) {
            return null;
        }
        $owner = RecoveryProcess::current();

        return DB::transaction(function () use ($owner, $config): ?RecoverySlot {
            DB::table('obfuscation_recovery_controls')->upsert([
                'scope' => 'pool', 'fingerprint' => hash('sha256', 'pool'), 'epoch' => (string) Str::uuid(),
                'generation' => 1, 'updated_at' => now(),
            ], ['scope'], ['scope']);
            DB::table('obfuscation_recovery_controls')->where('scope', 'pool')->lockForUpdate()->first();
            $slots = DB::table('obfuscation_recovery_slots');
            if ((clone $slots)->whereNotNull('worker_token')->count() >= $config->threads
                || (clone $slots)->where('owner_host', $owner->host)->where('owner_pid', $owner->pid)->where('owner_started', $owner->started)->exists()) {
                return null;
            }
            $slot = $slots->whereNull('worker_token')->orderBy('id')->lockForUpdate()->first();
            if ($slot === null) {
                return null;
            }
            $token = (string) Str::uuid();
            DB::table('obfuscation_recovery_slots')->where('id', $slot->id)->update([
                'worker_token' => $token, 'owner_host' => $owner->host, 'owner_pid' => $owner->pid,
                'owner_started' => $owner->started, 'acquired_at' => now(), 'expires_at' => now()->addSeconds(90),
            ]);

            return new RecoverySlot((int) $slot->id, $token, $owner);
        }, 1);
    }

    public function canStart(RecoverySlot $slot, RecoveryConfig $config): bool
    {
        if (! $config->enabled || RecoveryProcess::current() != $slot->owner) {
            return false;
        }
        $slots = DB::table('obfuscation_recovery_slots');
        if ((clone $slots)->whereNotNull('worker_token')->count() > $config->threads) {
            return false;
        }

        return $slots->where('id', $slot->id)->where('worker_token', $slot->token)
            ->where('owner_host', $slot->owner->host)->where('owner_pid', $slot->owner->pid)->where('owner_started', $slot->owner->started)
            ->where('expires_at', '>', now())->whereNull('attempt_id')->lockForUpdate()->exists();
    }

    public function release(RecoverySlot $slot): bool
    {
        $owner = RecoveryProcess::current();
        if ($owner != $slot->owner) {
            return false;
        }

        return DB::transaction(function () use ($slot, $owner): bool {
            $query = DB::table('obfuscation_recovery_slots')->where('id', $slot->id)->where('worker_token', $slot->token)
                ->where('owner_host', $owner->host)->where('owner_pid', $owner->pid)->where('owner_started', $owner->started);
            $current = (clone $query)->lockForUpdate()->first();
            if ($current === null) {
                return false;
            }
            $this->settleUnknown($current, 'closed_without_counter');

            return $query->update($this->emptySlot()) === 1;
        }, 1);
    }

    public function reap(): int
    {
        $reaped = 0;
        $slots = DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->where('expires_at', '<=', now())->get();
        foreach ($slots as $slot) {
            $owner = new RecoveryProcess($slot->owner_host, (int) $slot->owner_pid, $slot->owner_started);
            if ($owner->provenDead()) {
                $reaped += DB::transaction(function () use ($slot): int {
                    $current = DB::table('obfuscation_recovery_slots')->where('id', $slot->id)->where('worker_token', $slot->worker_token)
                        ->where('expires_at', '<=', now())->lockForUpdate()->first();
                    if ($current === null) {
                        return 0;
                    }
                    $this->settleUnknown($current, 'worker_lost');

                    return DB::table('obfuscation_recovery_slots')->where('id', $current->id)->where('worker_token', $current->worker_token)
                        ->update($this->emptySlot());
                }, 1);
            }
        }

        return $reaped;
    }

    private function settleUnknown(object $slot, string $outcome): void
    {
        if ($slot->attempt_id === null) {
            return;
        }
        $attempt = DB::table('obfuscation_recovery_attempts')->where('id', $slot->attempt_id)->whereNull('settled_at')->first();
        if ($attempt !== null) {
            app(RecoveryBudget::class)->settle(new RecoveryReservation((int) $attempt->id, $attempt->token,
                (int) $attempt->reserved_bytes, (int) $attempt->physical_attempt), null, 0, $outcome);
        }
    }

    /** @return array<string,null> */
    private function emptySlot(): array
    {
        return ['worker_token' => null, 'owner_host' => null, 'owner_pid' => null, 'owner_started' => null,
            'acquired_at' => null, 'expires_at' => null, 'attempt_id' => null];
    }
}
