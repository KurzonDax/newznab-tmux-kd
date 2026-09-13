<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Enums\ObfuscationRecoveryProfile;
use App\Services\NNTP\NntpProvider;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RecoveryBudget
{
    public function __construct(private readonly RecoveryIdentity $identity) {}

    /** @return Closure(bool):void */
    public function connectionObserver(RecoveryReservation $reservation): Closure
    {
        return static function (bool $open) use ($reservation): void {
            $values = $open ? ['connected_at' => now(), 'connections_opened' => 1] : ['closed_at' => now()];
            DB::table('obfuscation_recovery_attempts')->where('id', $reservation->attemptId)->where('token', $reservation->token)
                ->whereNull('settled_at')->update($values);
        };
    }

    public function reserve(string $owner, string $purpose, string $request, int $bytes, int $limit): ?RecoveryReservation
    {
        if ($bytes < 1 || $limit < 0 || ! in_array($purpose, ['construction', 'enrichment', 'gap', RecoveryFrontierRebuild::PURPOSE], true)) {
            throw new InvalidArgumentException('invalid_budget');
        }
        $requestDigest = $this->identity->digest(['request', $request]);

        return DB::transaction(function () use ($owner, $purpose, $requestDigest, $bytes, $limit): ?RecoveryReservation {
            $owners = new RecoveryBudgetOwners($this->identity);
            $ownerDigest = $owners->locked($owner);
            $members = $owners->members($owner);
            DB::table('obfuscation_recovery_budgets')->upsert([[
                'owner_digest' => $ownerDigest, 'purpose' => $purpose,
                'created_at' => now(), 'updated_at' => now(),
            ]], ['owner_digest', 'purpose'], ['updated_at']);
            $budgets = DB::table('obfuscation_recovery_budgets')->whereIn('owner_digest', $members)->where('purpose', $purpose)
                ->orderBy('id')->lockForUpdate()->get();
            $budget = $budgets->firstWhere('owner_digest', $ownerDigest);
            $spent = 0;
            foreach ($budgets as $account) {
                if ((int) $account->debited_bytes > PHP_INT_MAX - $spent) {
                    throw new InvalidArgumentException('accounting_overflow');
                }
                $spent += (int) $account->debited_bytes;
            }
            if ($budget === null || $bytes > $limit || $spent > $limit - $bytes) {
                return null;
            }
            $allBudgetIds = DB::table('obfuscation_recovery_budgets')->whereIn('owner_digest', $members)->pluck('id')->all();
            $previous = DB::table('obfuscation_recovery_attempts')->whereIn('budget_id', $allBudgetIds)
                ->where('request_digest', $requestDigest);
            $successful = (clone $previous)->where('outcome', 'success');
            if ($purpose === 'enrichment') {
                $successful->whereIn('budget_id', $budgets->pluck('id')->all());
            }
            if ((clone $previous)->whereNull('settled_at')->exists()
                || (clone $previous)->where('outcome', 'semantic_failure')->exists()
                || ($purpose !== RecoveryFrontierRebuild::PURPOSE && $successful->exists())) {
                return null;
            }
            $attempt = $previous->count() + 1;
            if ($attempt > 2) {
                return null;
            }
            $token = (string) Str::uuid();
            $id = DB::table('obfuscation_recovery_attempts')->insertGetId([
                'budget_id' => $budget->id, 'request_digest' => $requestDigest,
                'physical_attempt' => $attempt, 'token' => $token,
                'reserved_bytes' => $bytes, 'debited_bytes' => $bytes,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('obfuscation_recovery_budgets')->where('id', $budget->id)
                ->update(['debited_bytes' => (int) $budget->debited_bytes + $bytes, 'updated_at' => now()]);

            return new RecoveryReservation($id, $token, $bytes, $attempt);
        }, 1);
    }

    public function spent(string $owner, string $purpose): int
    {
        return (int) DB::table('obfuscation_recovery_budgets')
            ->whereIn('owner_digest', (new RecoveryBudgetOwners($this->identity))->members($owner))
            ->where('purpose', $purpose)->sum('debited_bytes');
    }

    public function reserveConstruction(RecoveryWorkClaim $claim, RecoverySlot $slot, string $request, int $bytes): ?RecoveryReservation
    {
        if ($bytes < 1 || $bytes > 2097152) {
            throw new InvalidArgumentException('invalid_construction_attempt_cap');
        }
        $request = $this->identity->messageId($request);

        return DB::transaction(function () use ($claim, $slot, $request, $bytes): ?RecoveryReservation {
            $bundle = (new RecoveryOwnership)->locked($claim);
            if ($bundle === null || $claim->stage !== RecoveryStage::Download) {
                return null;
            }
            $config = RecoveryConfig::fromSettings();
            $algorithm = RecoveryAlgorithm::tryFrom($bundle->profile ?? '');
            $selection = DB::table('usenet_groups')->where('id', $bundle->groups_id)->value('obfuscation_recovery_profile');
            if ($algorithm === null || ! $config->admits($selection, $algorithm->selection()) || RecoveryProcess::current() != $slot->owner) {
                return null;
            }
            $target = RecoveryConstructionTargets::find($bundle, $request);
            if ($target === null || $bytes !== RecoveryConstructionTargets::allowance($target['kind'], $algorithm)['reservation']) {
                return null;
            }
            if (! app(RecoverySlots::class)->canStart($slot, $config)) {
                return null;
            }
            $limit = $algorithm === RecoveryAlgorithm::Media ? $config->mediaCandidateBytes : $config->rarCandidateBytes;
            $reservation = $this->reserve($bundle->owner_digest, 'construction', $request, $bytes, $limit);
            if ($reservation !== null) {
                DB::table('obfuscation_recovery_attempts')->where('id', $reservation->attemptId)->update([
                    'groups_id' => $bundle->groups_id, 'profile' => $bundle->profile,
                ]);
                DB::table('obfuscation_recovery_slots')->where('id', $slot->id)->update(['attempt_id' => $reservation->attemptId]);
            }

            return $reservation;
        }, 1);
    }

    public function reserveGap(RecoveryWorkClaim $claim, RecoverySlot $slot, NntpProvider $provider): ?RecoveryReservation
    {
        return DB::transaction(function () use ($claim, $slot, $provider): ?RecoveryReservation {
            $bundle = (new RecoveryOwnership)->locked($claim);
            $frontier = $claim->purpose === RecoveryFrontierRebuild::PURPOSE;
            if ($bundle === null || $bundle->kind !== ($frontier ? 'frontier' : 'gap')
                || ! in_array($claim->purpose, ['gap', RecoveryFrontierRebuild::PURPOSE], true) || ! $provider->isPrimary()) {
                return null;
            }
            $gap = DB::table($frontier ? 'obfuscation_recovery_frontier_requests' : 'obfuscation_recovery_gaps')
                ->where('bundle_id', $bundle->id)->lockForUpdate()->first();
            $config = RecoveryConfig::fromSettings();
            $selection = DB::table('usenet_groups')->where('id', $bundle->groups_id)->value('obfuscation_recovery_profile');
            $fingerprint = $this->identity->digest([$provider->host, (string) $provider->port, (string) $provider->ssl, $provider->username]);
            $source = DB::table('obfuscation_recovery_controls')->where('scope', 'primary')->first();
            if ($gap === null || (int) $gap->requested_first < 1 || (int) $gap->requested_last < (int) $gap->requested_first
                || (int) $gap->requested_last - (int) $gap->requested_first >= 20000
                || $gap->expires_at <= now() || $source === null || $source->fingerprint !== $fingerprint
                || ! $config->admits($selection, RecoveryAlgorithm::from($bundle->profile)->selection())
                || (int) $gap->requested_first !== ($claim->payload['first'] ?? null) || (int) $gap->requested_last !== ($claim->payload['last'] ?? null)) {
                return null;
            }
            if (! app(RecoverySlots::class)->canStart($slot, $config)) {
                return null;
            }
            $request = $frontier ? $gap->budget_owner : implode(':', ['gap', $gap->source_epoch, $gap->groups_id, $gap->capture_generation, $gap->requested_first, $gap->requested_last]);
            $reservation = $this->reserve($frontier ? $gap->budget_owner : $bundle->owner_digest, $claim->purpose, $request, 33554432, 67108864);
            if ($reservation !== null) {
                DB::table('obfuscation_recovery_attempts')->where('id', $reservation->attemptId)->update([
                    'groups_id' => $bundle->groups_id, 'profile' => $bundle->profile,
                ]);
                DB::table('obfuscation_recovery_slots')->where('id', $slot->id)->update(['attempt_id' => $reservation->attemptId]);
            }

            return $reservation;
        }, 1);
    }

    public function frontierPending(RecoveryWorkClaim $claim): bool
    {
        $request = DB::table('obfuscation_recovery_frontier_requests')->where('bundle_id', $claim->bundleId)->first();
        if ($request === null || $claim->purpose !== RecoveryFrontierRebuild::PURPOSE) {
            return false;
        }
        $members = (new RecoveryBudgetOwners($this->identity))->members($request->budget_owner);
        $budgets = DB::table('obfuscation_recovery_budgets')->whereIn('owner_digest', $members)->where('purpose', RecoveryFrontierRebuild::PURPOSE);
        $attempts = DB::table('obfuscation_recovery_attempts')->whereIn('budget_id', (clone $budgets)->pluck('id'))
            ->where('request_digest', $this->identity->digest(['request', $request->budget_owner]));

        return (clone $attempts)->whereNull('settled_at')->exists()
            || ($attempts->count() < 2 && ! (clone $attempts)->where('outcome', 'semantic_failure')->exists()
                && (int) $budgets->sum('debited_bytes') <= 33554432);
    }

    public function reserveEnrichment(RecoveryWorkClaim $claim, RecoverySlot $slot): ?RecoveryReservation
    {
        return DB::transaction(function () use ($claim, $slot): ?RecoveryReservation {
            $bundle = (new RecoveryOwnership)->locked($claim);
            if ($bundle === null || $claim->stage !== RecoveryStage::Download || $claim->purpose !== 'enrichment') {
                return null;
            }
            $publication = DB::table('obfuscation_recovery_publications')->where('id', $claim->payload['publication_id'] ?? 0)->lockForUpdate()->first();
            $config = RecoveryConfig::fromSettings();
            $selection = ObfuscationRecoveryProfile::tryFrom(DB::table('usenet_groups')->where('id', $bundle->groups_id)->value('obfuscation_recovery_profile') ?? '');
            $algorithm = RecoveryAlgorithm::tryFrom($bundle->profile ?? '');
            if ($publication === null || $publication->state !== 'published' || $publication->deleted_at !== null
                || ! $config->enabled || ! $config->enrichmentEnabled || $algorithm === null || ! $selection?->permits($algorithm->selection())
                || RecoveryProcess::current() != $slot->owner) {
                return null;
            }
            $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
            if ($plan->bundleId !== $claim->bundleId || $plan->revision !== $claim->revision) {
                return null;
            }
            $target = DB::table('obfuscation_recovery_targets')->where('publication_id', $publication->id)
                ->where('file_id', $claim->payload['file_id'] ?? '')->where('message_id', $claim->payload['message_id'] ?? '')
                ->where('status', 'pending')->first();
            if ($target === null || ! app(RecoverySlots::class)->canStart($slot, $config)) {
                return null;
            }
            $owners = new RecoveryBudgetOwners($this->identity);
            $owners->locked($bundle->owner_digest);
            $members = $owners->members($bundle->owner_digest);
            $budgetIds = DB::table('obfuscation_recovery_budgets')->whereIn('owner_digest', $members)->where('purpose', 'enrichment')->pluck('id');
            $requests = DB::table('obfuscation_recovery_targets')->where('publication_id', $publication->id)->where('file_id', $target->file_id)->pluck('request_digest');
            $fileSpent = (int) DB::table('obfuscation_recovery_attempts')->whereIn('budget_id', $budgetIds)->whereIn('request_digest', $requests)->sum('debited_bytes');
            $fileLimit = $plan->algorithm === RecoveryAlgorithm::Rar || $plan->multiMediaInventory() ? 2097152 : 4194304;
            if ($fileSpent > $fileLimit - 2097152) {
                return null;
            }
            $reservation = $this->reserve($bundle->owner_digest, 'enrichment', $target->message_id, 2097152, $config->enrichmentReleaseBytes);
            if ($reservation !== null) {
                DB::table('obfuscation_recovery_attempts')->where('id', $reservation->attemptId)->update([
                    'groups_id' => $bundle->groups_id, 'profile' => $bundle->profile,
                ]);
                DB::table('obfuscation_recovery_slots')->where('id', $slot->id)->update(['attempt_id' => $reservation->attemptId]);
            }

            return $reservation;
        }, 1);
    }

    public function recordTransfer(RecoveryReservation $reservation, RecoveryTransfer $transfer, bool $tls, int $lateAllowance): bool
    {
        return DB::transaction(function () use ($reservation, $transfer, $tls, $lateAllowance): bool {
            $settled = $this->settle($reservation, $tls ? null : $transfer->plaintextReceived + $transfer->plaintextSent,
                $lateAllowance, $transfer->outcome);
            if (! $settled) {
                return false;
            }
            DB::table('obfuscation_recovery_attempts')->where('id', $reservation->attemptId)->where('token', $reservation->token)->update([
                'decoded_bytes' => $transfer->decodedBytes,
                'plaintext_bytes' => $transfer->plaintextReceived, 'transmitted_bytes' => $transfer->plaintextSent,
                'encrypted_bytes' => $transfer->encryptedReceived, 'socket_received_bytes' => $tls ? null : $transfer->plaintextReceived,
                'connections_opened' => $transfer->connectionsOpened, 'receive_window' => $transfer->receiveWindow,
                'buffered_at_close' => $transfer->bufferedAtClose, 'elapsed_milliseconds' => $transfer->elapsedMilliseconds,
                'closed_at' => $transfer->closed ? now() : null, 'failure_phase' => $transfer->reason,
            ]);

            return true;
        }, 1);
    }

    public function settle(RecoveryReservation $reservation, ?int $observedBytes, int $lateAllowance, string $outcome): bool
    {
        if ($observedBytes < 0 || $lateAllowance < 0 || $observedBytes > PHP_INT_MAX - $lateAllowance
            || ! in_array($outcome, ['success', 'transport_failure', 'semantic_failure', 'worker_lost', 'closed_without_counter', 'stopped'], true)) {
            throw new InvalidArgumentException('invalid_attempt_accounting');
        }
        $attempt = DB::table('obfuscation_recovery_attempts')->where('id', $reservation->attemptId)
            ->where('token', $reservation->token)->first();
        if ($attempt === null) {
            return false;
        }

        return DB::transaction(function () use ($attempt, $reservation, $observedBytes, $lateAllowance, $outcome): bool {
            $budget = DB::table('obfuscation_recovery_budgets')->where('id', $attempt->budget_id)->lockForUpdate()->first();
            $current = DB::table('obfuscation_recovery_attempts')->where('id', $reservation->attemptId)
                ->where('token', $reservation->token)->whereNull('settled_at')->lockForUpdate()->first();
            if ($budget === null || $current === null) {
                return false;
            }
            $debit = $observedBytes === null ? (int) $current->reserved_bytes : $observedBytes + $lateAllowance;
            $previous = (int) $budget->debited_bytes - (int) $current->debited_bytes;
            if ($previous < 0 || $debit > PHP_INT_MAX - $previous) {
                throw new InvalidArgumentException('accounting_overflow');
            }
            DB::table('obfuscation_recovery_budgets')->where('id', $budget->id)
                ->update(['debited_bytes' => $previous + $debit, 'updated_at' => now()]);
            DB::table('obfuscation_recovery_attempts')->where('id', $current->id)->update([
                'debited_bytes' => $debit, 'late_receive_allowance' => $lateAllowance,
                'outcome' => $outcome, 'settled_at' => now(), 'updated_at' => now(),
            ]);

            return true;
        }, 1);
    }
}
