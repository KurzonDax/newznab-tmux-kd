<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Persistence;

use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseMusicIdentification;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateSummary;
use App\Services\MusicIdentity\DTO\IdentificationDecision;
use App\Services\MusicIdentity\Enums\AcceptedIdentityScope;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\Exceptions\LostMusicIdentityLease;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class IdentificationDecisionStore
{
    public function __construct(private int $candidateAttemptLimit = 5) {}

    public function persist(
        int $releaseId,
        AudioEvidenceSet $evidence,
        IdentificationDecision $decision,
        ?DateTimeInterface $nextAttemptAt = null,
        ?string $leaseToken = null,
    ): ReleaseMusicIdentification {
        return DB::transaction(function () use ($releaseId, $evidence, $decision, $nextAttemptAt, $leaseToken): ReleaseMusicIdentification {
            $evidenceRecord = ReleaseAudioEvidence::query()->lockForUpdate()->findOrFail($evidence->evidenceId);
            if ($evidenceRecord->releases_id !== $releaseId || ! hash_equals($evidenceRecord->evidence_hash, $evidence->evidenceHash)) {
                throw new InvalidArgumentException('The audio evidence does not belong to the requested release and evidence hash.');
            }

            $existing = ReleaseMusicIdentification::query()
                ->where('releases_id', $releaseId)
                ->where('evidence_hash', $evidence->evidenceHash)
                ->where('algorithm_version', $decision->algorithmVersion)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->completeExistingAttempt($existing, $decision, $nextAttemptAt, $leaseToken);
            }

            $supersedesId = ReleaseMusicIdentification::query()
                ->where('releases_id', $releaseId)
                ->where('evidence_hash', $evidence->evidenceHash)
                ->latest('id')
                ->value('id');
            $identification = ReleaseMusicIdentification::query()->firstOrCreate([
                'releases_id' => $releaseId,
                'evidence_hash' => $evidence->evidenceHash,
                'algorithm_version' => $decision->algorithmVersion,
            ], [
                'release_audio_evidence_id' => $evidence->evidenceId,
                'supersedes_id' => $supersedesId,
                ...$this->decisionAttributes($decision, 1, $nextAttemptAt),
            ]);

            if (! $identification->wasRecentlyCreated) {
                return $this->completeExistingAttempt($identification, $decision, $nextAttemptAt, $leaseToken);
            }

            $this->persistCandidates($identification, $decision);

            return $identification->load('candidateAttempts');
        });
    }

    private function completeExistingAttempt(
        ReleaseMusicIdentification $identification,
        IdentificationDecision $decision,
        ?DateTimeInterface $nextAttemptAt,
        ?string $leaseToken,
    ): ReleaseMusicIdentification {
        if ($identification->state->isTerminal()) {
            return $identification;
        }
        if ($leaseToken !== null
            && ($identification->lease_token === null
                || ! hash_equals($identification->lease_token, $leaseToken)
                || $identification->lease_expires_at === null
                || ! $identification->lease_expires_at->isFuture())) {
            throw new LostMusicIdentityLease('The music identity work lease belongs to another worker.');
        }

        $identification->fill($this->decisionAttributes(
            $decision,
            $identification->attempt_count + 1,
            $nextAttemptAt,
        ));
        $identification->save();
        $this->persistCandidates($identification, $decision);

        return $identification->load('candidateAttempts');
    }

    /** @return array<string, mixed> */
    private function decisionAttributes(
        IdentificationDecision $decision,
        int $attemptCount,
        ?DateTimeInterface $nextAttemptAt,
    ): array {
        $featureContributions = $decision->candidates === []
            ? []
            : $decision->candidates[0]->scoreContributions;

        return [
            'state' => $decision->status,
            'score' => $decision->score,
            'band' => $decision->band,
            'accepted_scope' => $this->acceptedScope($decision->status),
            'musicbrainz_recording_id' => $decision->acceptedIdentity?->recordingId,
            'musicbrainz_release_id' => $decision->acceptedIdentity?->releaseId,
            'musicbrainz_release_group_id' => $decision->acceptedIdentity?->releaseGroupId,
            'reasons' => array_map(static fn ($reason): array => $reason->toArray(), $decision->reasons),
            'feature_contributions' => $featureContributions,
            'runner_up_margin' => $decision->runnerUpMargin,
            'attempt_count' => $attemptCount,
            'lease_token' => null,
            'lease_expires_at' => null,
            'next_attempt_at' => $decision->status === IdentificationStatus::RetryableError ? $nextAttemptAt : null,
            'last_operational_error' => $decision->operationalError,
            'resolver_version' => $decision->resolverVersion,
            'normalizer_version' => $decision->normalizerVersion,
            'scorer_version' => $decision->scorerVersion,
            'policy_version' => $decision->policyVersion,
            'decided_at' => $decision->status === IdentificationStatus::RetryableError ? null : now(),
        ];
    }

    private function persistCandidates(
        ReleaseMusicIdentification $identification,
        IdentificationDecision $decision,
    ): void {
        if ($identification->candidateAttempts()->exists()) {
            return;
        }

        foreach (array_slice($decision->candidates, 0, max(0, $this->candidateAttemptLimit)) as $index => $candidate) {
            $identification->candidateAttempts()->create($this->candidateAttributes($candidate, $index + 1));
        }
    }

    private function acceptedScope(IdentificationStatus $status): ?AcceptedIdentityScope
    {
        return match ($status) {
            IdentificationStatus::AcceptedRecording => AcceptedIdentityScope::Recording,
            IdentificationStatus::AcceptedReleaseGroup => AcceptedIdentityScope::ReleaseGroup,
            IdentificationStatus::AcceptedEdition => AcceptedIdentityScope::Edition,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function candidateAttributes(CandidateSummary $candidate, int $rank): array
    {
        return [
            'rank' => $rank,
            'score' => $candidate->score,
            'musicbrainz_recording_id' => $candidate->identity->recordingId,
            'musicbrainz_release_id' => $candidate->identity->releaseId,
            'musicbrainz_release_group_id' => $candidate->identity->releaseGroupId,
            'display_snapshot' => $candidate->displaySnapshot,
            'feature_vector' => $candidate->featureVector,
            'score_contributions' => $candidate->scoreContributions,
            'contradictions' => $candidate->contradictions,
            'provenance' => $candidate->provenanceFamilies,
            'response_cache_keys' => $candidate->responseCacheKeys,
        ];
    }
}
