<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Durable provenance for a successful transfer, independent of cache installation. */
final class RecoveryTransferReceipt
{
    public function __construct(private readonly RecoveryArtifacts $artifacts) {}

    /** @param array<string,mixed> $metadata */
    public function record(RecoveryWorkClaim $claim, RecoveryReservation $reservation, RecoveryTransfer $transfer,
        bool $tls, int $lateAllowance, string $kind, string $key, array $metadata, string $data): void
    {
        $receipt = $transfer->outcome === 'success' ? [
            'kind' => $kind, 'key' => $key, 'work_id' => $claim->id, 'bundle_id' => $claim->bundleId,
            'revision' => $claim->revision, 'metadata' => $metadata,
            'artifact' => hash('sha256', $data), 'bytes' => strlen($data),
        ] : null;
        try {
            $settled = DB::transaction(function () use ($reservation, $transfer, $tls, $lateAllowance, $receipt): bool {
                if (! app(RecoveryBudget::class)->recordTransfer($reservation, $transfer, $tls, $lateAllowance)) {
                    return false;
                }
                if ($receipt !== null) {
                    DB::table('obfuscation_recovery_attempts')->where('id', $reservation->attemptId)
                        ->update(['handoff' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
                    (new RecoveryReferences)->retain('bundle', (string) $receipt['bundle_id'], 'artifact', $receipt['artifact']);
                }

                return true;
            }, 1);
        } catch (\Throwable $exception) {
            throw new RuntimeException('transfer_receipt_pending', previous: $exception);
        }

        if ($settled && $receipt !== null) {
            try {
                $this->artifacts->put([$data], $kind === 'article' ? 1048576 : 50331648);
            } catch (\Throwable $exception) {
                throw new RuntimeException('transfer_artifact_pending', previous: $exception);
            }
        }
    }

    /** @return array{attempt_id:int,metadata:array<string,mixed>,data:string}|null */
    public function recover(RecoveryWorkClaim $claim, string $kind, string $key): ?array
    {
        $bundle = DB::transaction(fn (): ?object => (new RecoveryOwnership)->locked($claim), 1);

        return $bundle === null ? null : $this->available($bundle, $kind, $key);
    }

    /** @return array{attempt_id:int,metadata:array<string,mixed>,data:string}|null */
    public function available(object $bundle, string $kind, string $key): ?array
    {
        $budgets = DB::table('obfuscation_recovery_budgets')
            ->whereIn('owner_digest', (new RecoveryBudgetOwners(new RecoveryIdentity))->members($bundle->owner_digest))->pluck('id');
        $query = DB::table('obfuscation_recovery_attempts')->whereIn('budget_id', $budgets)
            ->where('request_digest', (new RecoveryIdentity)->digest(['request', $key]));
        if ((clone $query)->where(fn ($failures) => $failures->where('outcome', 'semantic_failure')->orWhere('handoff_conflict', true))->exists()) {
            throw new \InvalidArgumentException('conflicting_transfer_receipt');
        }
        $attempts = $query->where('outcome', 'success')->whereNotNull('handoff')->orderByDesc('id')->limit(2)->get();
        foreach ($attempts as $attempt) {
            if ($attempt->outcome !== 'success' || $attempt->handoff === null) {
                continue;
            }
            $receipt = json_decode($attempt->handoff, true, flags: JSON_THROW_ON_ERROR);
            if ($receipt['kind'] !== $kind || $receipt['key'] !== $key
                || ($kind !== 'article' && ($receipt['bundle_id'] !== (int) $bundle->id || $receipt['revision'] !== (int) $bundle->revision))) {
                continue;
            }
            try {
                $data = '';
                foreach ($this->artifacts->read(new RecoveryArtifact($receipt['artifact'], $receipt['bytes'])) as $chunk) {
                    $data .= $chunk;
                }

                return ['attempt_id' => (int) $attempt->id, 'metadata' => $receipt['metadata'], 'data' => $data];
            } catch (RuntimeException $exception) {
                if (! in_array($exception->getMessage(), ['artifact_missing', 'artifact_integrity_failure'], true)) {
                    throw $exception;
                }
            }
        }

        return null;
    }
}
