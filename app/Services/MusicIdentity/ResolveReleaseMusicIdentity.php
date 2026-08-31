<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity;

use App\Models\Release;
use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseMusicIdentification;
use App\Services\AudioProcessing\AudioEvidenceSynthesizer;
use App\Services\MusicIdentity\DTO\DecisionReason;
use App\Services\MusicIdentity\DTO\IdentificationDecision;
use App\Services\MusicIdentity\Enums\IdentificationBand;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\Evidence\AudioEvidenceSetFactory;
use App\Services\MusicIdentity\Exceptions\LostMusicIdentityLease;
use App\Services\MusicIdentity\Persistence\IdentificationDecisionStore;
use App\Services\MusicIdentity\Persistence\MusicIdentityLeaseManager;
use App\Services\MusicIdentity\Persistence\MusicIdentitySynthesisLeaseManager;
use Illuminate\Support\Facades\Log;

/**
 * Application boundary for evidence-based music identity work.
 */
final readonly class ResolveReleaseMusicIdentity
{
    public function __construct(
        private MusicIdentityConfiguration $configuration,
        private AudioEvidenceSynthesizer $synthesizer,
        private AudioEvidenceSetFactory $evidenceFactory,
        private MusicIdentityResolver $resolver,
        private MusicIdentityLeaseManager $leases,
        private MusicIdentitySynthesisLeaseManager $synthesisLeases,
        private MusicIdentityRetryPolicy $retryPolicy,
        private IdentificationDecisionStore $decisions,
    ) {}

    /** @return list<object{id: string}> */
    public function eligibleBuckets(): array
    {
        return $this->configuration->active()
            ? MusicIdentityCandidateQuery::buckets()
            : [];
    }

    public function eligibleCount(): int
    {
        return $this->configuration->active()
            ? MusicIdentityCandidateQuery::query()->count()
            : 0;
    }

    public function workerParallelism(): int
    {
        return $this->configuration->workerParallelism;
    }

    public function process(int|string $groupId = '', string $guidChar = ''): int
    {
        if (! $this->configuration->active()) {
            return 0;
        }

        $workerToken = bin2hex(random_bytes(16));
        $limit = max(1, (int) config('music-identity.worker_batch_size', 25));
        $releases = MusicIdentityCandidateQuery::query($groupId, $guidChar)
            ->orderByDesc('r.postdate')
            ->limit($limit)
            ->get();
        $completed = 0;

        foreach ($releases as $release) {
            if ($this->resolveRelease($release, $workerToken) !== null) {
                $completed++;
            }
        }

        return $completed;
    }

    public function resolveRelease(
        Release $release,
        ?string $workerToken = null,
    ): ?ReleaseMusicIdentification {
        if (! $this->configuration->active()) {
            return null;
        }

        $workerToken ??= bin2hex(random_bytes(16));
        $evidenceRecord = ReleaseAudioEvidence::query()
            ->where('releases_id', $release->id)
            ->orderByDesc('revision')
            ->first();
        if ($evidenceRecord === null) {
            $synthesisAttemptCount = $this->synthesisLeases->acquire((int) $release->id, $workerToken);
            if ($synthesisAttemptCount === null) {
                return null;
            }

            try {
                $evidenceRecord = $this->synthesizer->synthesizeIfMissing($release);
                $this->synthesisLeases->complete((int) $release->id, $workerToken);
            } catch (\Throwable $exception) {
                $recorded = $this->synthesisLeases->fail(
                    releaseId: (int) $release->id,
                    workerToken: $workerToken,
                    attemptCount: $synthesisAttemptCount,
                    operationalError: $exception->getMessage(),
                );
                Log::warning('Music identity evidence synthesis failed.', [
                    'release_id' => $release->id,
                    'failure_recorded' => $recorded,
                    'exception' => $exception,
                ]);

                return null;
            }
        }

        $lease = $this->leases->acquire($evidenceRecord, $workerToken);
        if ($lease === null) {
            return null;
        }

        try {
            $evidence = $this->evidenceFactory->make($evidenceRecord);
            if (! $this->leases->renew($lease->id, $workerToken)) {
                throw new LostMusicIdentityLease('The music identity work lease expired before resolution.');
            }
            $decision = $this->resolver->resolve($evidence);
            if (! $this->leases->renew($lease->id, $workerToken)) {
                throw new LostMusicIdentityLease('The music identity work lease expired during resolution.');
            }

            return $this->decisions->persist(
                releaseId: (int) $release->id,
                evidence: $evidence,
                decision: $decision,
                nextAttemptAt: $decision->status === IdentificationStatus::RetryableError
                    ? $this->retryPolicy->nextAttemptAt($lease->attempt_count)
                    : null,
                leaseToken: $workerToken,
            );
        } catch (LostMusicIdentityLease $exception) {
            Log::notice('Music identity work lease was lost.', [
                'release_id' => $release->id,
                'identification_id' => $lease->id,
                'message' => $exception->getMessage(),
            ]);

            return null;
        } catch (\Throwable $exception) {
            Log::error('Music identity worker failed.', [
                'release_id' => $release->id,
                'identification_id' => $lease->id,
                'exception' => $exception,
            ]);

            try {
                return $this->decisions->persist(
                    releaseId: (int) $release->id,
                    evidence: $this->evidenceFactory->make($evidenceRecord),
                    decision: $this->operationalErrorDecision($exception),
                    nextAttemptAt: $this->retryPolicy->nextAttemptAt($lease->attempt_count),
                    leaseToken: $workerToken,
                );
            } catch (\Throwable $persistenceException) {
                Log::error('Music identity operational failure could not be persisted.', [
                    'release_id' => $release->id,
                    'identification_id' => $lease->id,
                    'exception' => $persistenceException,
                ]);

                return null;
            }
        }
    }

    private function operationalErrorDecision(\Throwable $exception): IdentificationDecision
    {
        return new IdentificationDecision(
            status: IdentificationStatus::RetryableError,
            score: 0,
            band: IdentificationBand::Unresolved,
            acceptedIdentity: null,
            reasons: [new DecisionReason('worker_operational_error', $exception->getMessage())],
            candidates: [],
            runnerUpMargin: null,
            algorithmVersion: (string) config('music-identity.algorithm_version', 'music-identity-v1'),
            resolverVersion: (string) config('music-identity.resolver_version', 'resolver-v1'),
            normalizerVersion: (string) config('music-identity.normalizer_version', 'normalizer-v1'),
            scorerVersion: (string) config('music-identity.scorer_version', 'whole-release-v1'),
            policyVersion: (string) config('music-identity.policy_version', 'shadow-v1'),
            operationalError: $exception->getMessage(),
        );
    }
}
