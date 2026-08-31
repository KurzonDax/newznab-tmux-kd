<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Persistence;

use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseMusicIdentification;
use App\Services\MusicIdentity\Enums\IdentificationBand;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Owns atomic acquisition and renewal of one evidence/version work lease.
 */
final class MusicIdentityLeaseManager
{
    public function acquire(
        ReleaseAudioEvidence $evidence,
        string $workerToken,
    ): ?ReleaseMusicIdentification {
        return DB::transaction(function () use ($evidence, $workerToken): ?ReleaseMusicIdentification {
            $lockedEvidence = ReleaseAudioEvidence::query()->lockForUpdate()->findOrFail($evidence->id);
            $algorithmVersion = (string) config('music-identity.algorithm_version', 'music-identity-v1');
            $identification = ReleaseMusicIdentification::query()
                ->where('releases_id', $lockedEvidence->releases_id)
                ->where('evidence_hash', $lockedEvidence->evidence_hash)
                ->where('algorithm_version', $algorithmVersion)
                ->lockForUpdate()
                ->first();

            if ($identification === null) {
                $identification = ReleaseMusicIdentification::query()->firstOrCreate([
                    'releases_id' => $lockedEvidence->releases_id,
                    'evidence_hash' => $lockedEvidence->evidence_hash,
                    'algorithm_version' => $algorithmVersion,
                ], $this->pendingAttributes($lockedEvidence, $workerToken));

                if ($identification->wasRecentlyCreated) {
                    return $identification;
                }

                $identification->refresh();
            }

            if ($identification->state->isTerminal()) {
                return null;
            }
            if ($identification->state === IdentificationStatus::RetryableError
                && $identification->next_attempt_at !== null
                && $identification->next_attempt_at->isFuture()) {
                return null;
            }
            if ($identification->lease_token !== $workerToken
                && $identification->lease_expires_at !== null
                && $identification->lease_expires_at->isFuture()) {
                return null;
            }

            $identification->forceFill([
                'lease_token' => $workerToken,
                'lease_expires_at' => $this->leaseExpiry(),
            ])->save();

            return $identification->fresh();
        }, 3);
    }

    /** @phpstan-impure */
    public function renew(int $identificationId, string $workerToken): bool
    {
        $heldLease = ReleaseMusicIdentification::query()
            ->whereKey($identificationId)
            ->where('lease_token', $workerToken)
            ->where(function (Builder $leaseQuery): void {
                $leaseQuery
                    ->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '>', now());
            });

        $heldLease->update(['lease_expires_at' => $this->leaseExpiry()]);

        return $heldLease->exists();
    }

    /** @return array<string, mixed> */
    private function pendingAttributes(ReleaseAudioEvidence $evidence, string $workerToken): array
    {
        $supersedesId = ReleaseMusicIdentification::query()
            ->where('releases_id', $evidence->releases_id)
            ->where('evidence_hash', $evidence->evidence_hash)
            ->latest('id')
            ->value('id');

        return [
            'release_audio_evidence_id' => $evidence->id,
            'state' => IdentificationStatus::Pending,
            'score' => 0,
            'band' => IdentificationBand::Unresolved,
            'accepted_scope' => null,
            'reasons' => [],
            'feature_contributions' => [],
            'attempt_count' => 0,
            'lease_token' => $workerToken,
            'lease_expires_at' => $this->leaseExpiry(),
            'next_attempt_at' => null,
            'resolver_version' => (string) config('music-identity.resolver_version', 'resolver-v1'),
            'normalizer_version' => (string) config('music-identity.normalizer_version', 'normalizer-v1'),
            'scorer_version' => (string) config('music-identity.scorer_version', 'whole-release-v1'),
            'policy_version' => (string) config('music-identity.policy_version', 'shadow-v1'),
            'supersedes_id' => $supersedesId,
            'decided_at' => null,
        ];
    }

    private function leaseExpiry(): \DateTimeInterface
    {
        return now()->addSeconds(max(1, (int) config('music-identity.lease_seconds', 300)));
    }
}
