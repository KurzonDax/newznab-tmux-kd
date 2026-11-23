<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity;

use App\Models\Release;
use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseMusicIdentification;
use App\Services\AudioProcessing\AudioEvidenceSynthesizer;
use App\Services\DecisionModel\DecisionLog;
use App\Services\DecisionModel\DecisionMode;
use App\Services\MusicIdentity\CoverArt\AlbumCoverFetcher;
use App\Services\MusicIdentity\DecisionModel\MusicAlbumCheck;
use App\Services\MusicIdentity\DTO\DecisionReason;
use App\Services\MusicIdentity\DTO\IdentificationDecision;
use App\Services\MusicIdentity\Enums\IdentificationBand;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\Evidence\AudioEvidenceSetFactory;
use App\Services\MusicIdentity\Exceptions\LostMusicIdentityLease;
use App\Services\MusicIdentity\Persistence\IdentificationDecisionStore;
use App\Services\MusicIdentity\Persistence\MusicIdentityLeaseManager;
use App\Services\MusicIdentity\Persistence\MusicIdentitySynthesisLeaseManager;
use App\Services\MusicIdentity\Rename\MusicRenameProjection;
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
        private AlbumCoverFetcher $covers,
        private MusicRenameProjection $renames,
        private MusicAlbumCheck $albumChecks,
        private DecisionLog $decisionLog = new DecisionLog,
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

    /**
     * Once per `mus` pass, whether or not any release awaits identification: covers for current
     * accepted albums whose lookup has no outcome yet, already-accepted releases included (#1015).
     */
    public function catchUpCovers(): int
    {
        if (! $this->configuration->active()) {
            return 0;
        }

        try {
            return $this->covers->backfill();
        } catch (\Throwable $exception) {
            Log::warning('Album cover catch-up failed.', ['exception' => $exception]);

            return 0;
        }
    }

    /**
     * Once per `mus` pass: the canonical rename for current accepted albums with no rename record
     * yet, already-accepted releases included, and the reversal of renames a newer decision
     * replaced or withdrew (#309).
     */
    public function catchUpRenames(): int
    {
        if (! $this->configuration->active()) {
            return 0;
        }

        try {
            return $this->renames->catchUp();
        } catch (\Throwable $exception) {
            Log::warning('Music rename catch-up failed.', ['exception' => $exception]);

            return 0;
        }
    }

    /**
     * Once per `mus` pass, after renames and covers: the decision model's shadow check of current
     * matcher decisions not yet logged under the current criteria, at most a batch per pass (#1140).
     */
    public function catchUpAlbumChecks(): int
    {
        if (! $this->configuration->active()) {
            return 0;
        }

        try {
            return $this->albumChecks->catchUp();
        } catch (\Throwable $exception) {
            Log::warning('Music album check catch-up failed.', ['exception' => $exception]);

            return 0;
        }
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

            $identification = $this->decisions->persist(
                releaseId: (int) $release->id,
                evidence: $evidence,
                decision: $decision,
                nextAttemptAt: match (true) {
                    $decision->status === IdentificationStatus::RetryableError => $this->retryAt($release, $lease),
                    $decision->status->isRecheckable() => $this->retryPolicy->recheckAt($release->postdate),
                    default => null,
                },
                leaseToken: $workerToken,
            );
            $this->recordAlbumPick($decision, $identification);
            $this->projectRename($identification);
            $this->fetchCover($identification);

            return $identification;
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
                    nextAttemptAt: $this->retryAt($release, $lease),
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

    /**
     * When a failed attempt is tried again: a failed later look at a decision without an album
     * (#1277) only while the release is young enough to gain one, any other failure on the retry
     * delay.
     */
    private function retryAt(Release $release, ReleaseMusicIdentification $lease): ?\DateTimeInterface
    {
        return $lease->state->isRecheckable()
            ? $this->retryPolicy->recheckRetryAt($release->postdate, $lease->attempt_count)
            : $this->retryPolicy->nextAttemptAt($lease->attempt_count);
    }

    /**
     * The decision model's answer to resolution step 9 (#1275) is logged once its decision is
     * stored complete, linked to the stored row, so the shadow catch-up never asks about that row
     * again under the same criteria. A retryable attempt logs nothing: the retry asks again.
     * Logging never changes or fails the persisted decision.
     */
    private function recordAlbumPick(IdentificationDecision $decision, ReleaseMusicIdentification $identification): void
    {
        $pick = $decision->albumPick;
        if ($pick === null || ! $identification->state->isTerminal()) {
            return;
        }

        try {
            $this->decisionLog->record($pick->run, MusicAlbumCheck::SUBJECT_TYPE, (int) $identification->id, $pick->pipelineAnswer, DecisionMode::Apply, $pick->applied);
        } catch (\Throwable $exception) {
            Log::warning('Music album pick could not be logged.', ['identification_id' => $identification->id, 'exception' => $exception]);
        }
    }

    /**
     * A completed decision renames the release for an accepted album, or reverts the rename of the
     * decision it replaced; rename work never changes or fails the persisted decision.
     */
    private function projectRename(ReleaseMusicIdentification $identification): void
    {
        if (! $identification->state->isTerminal()) {
            return;
        }

        try {
            $this->renames->project($identification->releases_id);
        } catch (\Throwable $exception) {
            Log::warning('Music rename projection failed.', ['identification_id' => $identification->id, 'exception' => $exception]);
        }
    }

    /** The accepted album's cover; cover work never changes or fails the persisted decision. */
    private function fetchCover(ReleaseMusicIdentification $identification): void
    {
        try {
            $this->covers->fetchFor(CurrentMusicIdentity::fromRow($identification));
        } catch (\Throwable $exception) {
            Log::warning('Album cover lookup failed.', ['identification_id' => $identification->id, 'exception' => $exception]);
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
            algorithmVersion: MusicIdentityConfiguration::algorithmVersion(),
            resolverVersion: (string) config('music-identity.resolver_version', 'resolver-v1'),
            normalizerVersion: (string) config('music-identity.normalizer_version', 'normalizer-v1'),
            scorerVersion: (string) config('music-identity.scorer_version', 'whole-release-v1'),
            policyVersion: (string) config('music-identity.policy_version', 'shadow-v1'),
            operationalError: $exception->getMessage(),
        );
    }
}
