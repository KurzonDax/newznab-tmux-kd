<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity;

use App\Services\MusicIdentity\Contracts\CandidateGenerator;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateEvaluation;
use App\Services\MusicIdentity\DTO\CandidateIdentity;
use App\Services\MusicIdentity\DTO\CandidateSignal;
use App\Services\MusicIdentity\DTO\CandidateSummary;
use App\Services\MusicIdentity\DTO\DecisionReason;
use App\Services\MusicIdentity\DTO\IdentificationDecision;
use App\Services\MusicIdentity\Enums\CandidateSignalKind;
use App\Services\MusicIdentity\Enums\IdentificationBand;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\Exceptions\MusicBrainzGatewayException;
use App\Services\MusicIdentity\Matching\WholeReleaseAlignmentScorer;
use LogicException;

final readonly class MusicIdentityResolver
{
    public function __construct(
        private CandidateGenerator $candidateGenerator,
        private WholeReleaseAlignmentScorer $scorer = new WholeReleaseAlignmentScorer,
        private string $algorithmVersion = 'music-identity-v1',
        private string $resolverVersion = 'resolver-v1',
        private string $normalizerVersion = 'normalizer-v1',
        private string $scorerVersion = 'whole-release-v1',
        private string $policyVersion = 'shadow-v1',
        private int $minimumAlbumScore = 92,
        private int $minimumRunnerUpMargin = 5,
    ) {}

    public function resolve(AudioEvidenceSet $evidence): IdentificationDecision
    {
        try {
            $pool = $this->candidateGenerator->generate($evidence);
        } catch (MusicBrainzGatewayException $exception) {
            return $this->terminalDecision(IdentificationStatus::RetryableError, 'provider_retryable_error', $exception->getMessage());
        }

        $evaluations = array_map(
            fn ($candidate): CandidateEvaluation => $this->scorer->score($evidence, $candidate),
            $pool->candidates,
        );
        usort($evaluations, static fn (CandidateEvaluation $left, CandidateEvaluation $right): int => $right->score <=> $left->score);

        if ($evaluations === []) {
            return $this->terminalDecision(IdentificationStatus::Unresolved, 'no_candidates', null);
        }

        $embeddedReleases = $this->validatedEmbeddedReleases($evaluations);
        $embeddedReleaseGroups = $this->validatedEmbeddedReleaseGroups($evaluations);
        $embeddedRecordings = $this->validatedEmbeddedRecordings($evaluations);
        $best = $evaluations[0];
        $summaries = array_map(static fn (CandidateEvaluation $evaluation): CandidateSummary => $evaluation->summary, $evaluations);
        if ($this->hasIncompatibleEmbeddedIdentities($embeddedReleases, $embeddedReleaseGroups)) {
            return $this->decision(
                IdentificationStatus::Conflicted,
                $best,
                null,
                [new DecisionReason('incompatible_embedded_release_ids', 'Validated embedded release identifiers point to incompatible release groups.')],
                $summaries,
                null,
            );
        }

        $hasEmbeddedIdentity = $embeddedReleases !== [] || $embeddedReleaseGroups !== [] || $embeddedRecordings !== [];
        $eligibleEvaluations = array_values(array_filter(
            $evaluations,
            fn (CandidateEvaluation $evaluation): bool => $this->supportsEmbeddedIdentities(
                $evaluation,
                $embeddedReleases,
                $embeddedReleaseGroups,
                $embeddedRecordings,
            ),
        ));
        if ($hasEmbeddedIdentity && $eligibleEvaluations === []) {
            return $this->decision(
                IdentificationStatus::Conflicted,
                $best,
                null,
                [new DecisionReason('incompatible_embedded_identifiers', 'No candidate reconciles all validated embedded identifiers.')],
                $summaries,
                null,
            );
        }
        if ($hasEmbeddedIdentity) {
            usort($evaluations, fn (CandidateEvaluation $left, CandidateEvaluation $right): int => (int) $this->supportsEmbeddedIdentities(
                $right,
                $embeddedReleases,
                $embeddedReleaseGroups,
                $embeddedRecordings,
            ) <=> (int) $this->supportsEmbeddedIdentities(
                $left,
                $embeddedReleases,
                $embeddedReleaseGroups,
                $embeddedRecordings,
            ) ?: $right->score <=> $left->score);
            $summaries = array_map(static fn (CandidateEvaluation $evaluation): CandidateSummary => $evaluation->summary, $evaluations);
        } else {
            $eligibleEvaluations = $evaluations;
        }
        $best = $eligibleEvaluations[0];

        if ($best->hasHardContradiction()) {
            return $this->decision(
                IdentificationStatus::Conflicted,
                $best,
                null,
                [new DecisionReason('hard_contradiction', implode(', ', $best->contradictions))],
                $summaries,
                null,
            );
        }

        $status = $this->acceptedStatus($best);
        $sameGroupEmbeddedEditionAmbiguity = count($embeddedReleases) > 1;
        if ($sameGroupEmbeddedEditionAmbiguity && $status === IdentificationStatus::AcceptedEdition) {
            $status = IdentificationStatus::AcceptedReleaseGroup;
        }

        $runnerUp = $this->runnerUpForScope($eligibleEvaluations, $best, $status);
        if ($status === IdentificationStatus::AcceptedEdition
            && $embeddedReleases === []
            && $runnerUp !== null
            && $this->sameReleaseGroup($best, $runnerUp)
            && $best->score - $runnerUp->score < $this->minimumRunnerUpMargin) {
            $status = IdentificationStatus::AcceptedReleaseGroup;
            $runnerUp = $this->runnerUpForScope($eligibleEvaluations, $best, $status);
        }
        $runnerUpMargin = $runnerUp === null ? null : $best->score - $runnerUp->score;
        if ($runnerUpMargin !== null && $runnerUpMargin < $this->minimumRunnerUpMargin && $best->score >= 75) {
            return $this->decision(
                IdentificationStatus::NeedsReview,
                $best,
                null,
                [new DecisionReason('runner_up_too_close', 'Plausible candidates are not sufficiently separated.')],
                $summaries,
                $runnerUpMargin,
            );
        }

        if ($status !== null) {
            return $this->decision(
                $status,
                $best,
                $this->acceptedIdentity($status, $best),
                [new DecisionReason('structural_gate_passed', 'The candidate passed an identity-scope structural gate.', $best->score)],
                $summaries,
                $runnerUpMargin,
            );
        }

        $status = $best->score >= 75 ? IdentificationStatus::NeedsReview : IdentificationStatus::Unresolved;

        return $this->decision(
            $status,
            $best,
            null,
            [new DecisionReason('structural_gate_not_met', 'The score cannot bypass the required structural evidence gate.')],
            $summaries,
            $runnerUpMargin,
        );
    }

    private function acceptedStatus(CandidateEvaluation $evaluation): ?IdentificationStatus
    {
        $candidate = $evaluation->candidate;
        $signals = $candidate->signals;
        $hasValidatedRelease = $this->hasExactSignal($signals, CandidateSignalKind::EmbeddedReleaseId);
        $hasExactDisc = $this->hasExactSignal($signals, CandidateSignalKind::DiscId)
            || $this->hasExactSignal($signals, CandidateSignalKind::DiscToc);
        $hasEditionEvidence = $hasValidatedRelease
            || $hasExactDisc
            || $this->hasExactSignal($signals, CandidateSignalKind::Barcode)
            || $this->hasExactSignal($signals, CandidateSignalKind::CatalogNumber)
            || ($evaluation->features['country_agreement'] ?? false) === true
            || ($evaluation->features['media_format_agreement'] ?? false) === true
            || (($evaluation->features['edition_date_agreement'] ?? false) === true
                && ($evaluation->features['original_date_agreement'] ?? false) === false)
            || (($evaluation->features['medium_count_agreement'] ?? false) === true
                && $evaluation->alignment->candidateCoverage >= 0.95
                && $evaluation->alignment->orderAgreement >= 0.9);
        $distinctRecordings = $candidate->independentRecordingSupport();
        $strongAlignment = $evaluation->alignment->observedCoverage >= 0.75
            && $evaluation->alignment->titleAgreement >= 0.8;
        $strongAlbumText = ($evaluation->features['release_title_agreement'] ?? 0.0) >= 0.85
            && ($evaluation->features['artist_credit_agreement'] ?? 0.0) >= 0.8
            && (($evaluation->features['edition_date_agreement'] ?? false) === true
                || ($evaluation->features['original_date_agreement'] ?? false) === true);
        $albumGate = $hasValidatedRelease
            || ($hasExactDisc && $strongAlignment)
            || ($distinctRecordings >= 3 && $strongAlignment)
            || ($distinctRecordings >= 2 && $strongAlbumText);

        if ($albumGate && $evaluation->score >= $this->minimumAlbumScore) {
            if ($hasEditionEvidence && $evaluation->alignedIdentity->releaseId !== null) {
                return IdentificationStatus::AcceptedEdition;
            }
            if ($evaluation->alignedIdentity->releaseGroupId !== null) {
                return IdentificationStatus::AcceptedReleaseGroup;
            }
        }

        $hasRecordingEvidence = $this->hasExactSignal($signals, CandidateSignalKind::EmbeddedRecordingId)
            || $this->hasExactSignal($signals, CandidateSignalKind::EmbeddedReleaseTrackId)
            || $this->hasExactSignal($signals, CandidateSignalKind::Isrc)
            || $this->hasExactSignal($signals, CandidateSignalKind::Fingerprint)
            || $this->hasSignal($signals, CandidateSignalKind::TrackEvidenceSearch);
        if ($hasRecordingEvidence
            && $candidate->uniqueRecordingId() !== null
            && $evaluation->score >= 75) {
            return IdentificationStatus::AcceptedRecording;
        }

        return null;
    }

    /**
     * @param  list<CandidateEvaluation>  $evaluations
     * @return array<string, string|null>
     */
    private function validatedEmbeddedReleases(array $evaluations): array
    {
        $releases = [];
        foreach ($evaluations as $evaluation) {
            foreach ($evaluation->candidate->signals as $signal) {
                if ($signal->kind !== CandidateSignalKind::EmbeddedReleaseId || ! $signal->exact) {
                    continue;
                }
                $releaseId = $signal->identity->releaseId ?? $evaluation->candidate->identity->releaseId;
                if ($releaseId !== null) {
                    $releases[$releaseId] = $signal->identity->releaseGroupId
                        ?? $evaluation->candidate->identity->releaseGroupId;
                }
            }
        }

        return $releases;
    }

    /**
     * @param  list<CandidateEvaluation>  $evaluations
     * @return list<string>
     */
    private function validatedEmbeddedReleaseGroups(array $evaluations): array
    {
        $releaseGroupIds = [];
        foreach ($evaluations as $evaluation) {
            foreach ($evaluation->candidate->signals as $signal) {
                if ($signal->kind === CandidateSignalKind::EmbeddedReleaseGroupId && $signal->exact) {
                    $releaseGroupId = $signal->identity->releaseGroupId ?? $evaluation->candidate->identity->releaseGroupId;
                    if ($releaseGroupId !== null) {
                        $releaseGroupIds[] = $releaseGroupId;
                    }
                }
            }
        }

        return array_values(array_unique($releaseGroupIds));
    }

    /**
     * @param  list<CandidateEvaluation>  $evaluations
     * @return list<string>
     */
    private function validatedEmbeddedRecordings(array $evaluations): array
    {
        $recordingIds = [];
        foreach ($evaluations as $evaluation) {
            foreach ($evaluation->candidate->signals as $signal) {
                if (! $signal->exact || ! in_array($signal->kind, [
                    CandidateSignalKind::EmbeddedRecordingId,
                    CandidateSignalKind::EmbeddedReleaseTrackId,
                ], true)) {
                    continue;
                }
                $recordingId = $signal->identity->recordingId;
                if ($recordingId === null && $signal->kind === CandidateSignalKind::EmbeddedRecordingId) {
                    $recordingId = $signal->value;
                }
                if ($recordingId !== null) {
                    $recordingIds[] = $recordingId;
                }
            }
        }

        return array_values(array_unique($recordingIds));
    }

    /**
     * @param  array<string, string|null>  $embeddedReleases
     * @param  list<string>  $embeddedReleaseGroups
     * @param  list<string>  $embeddedRecordings
     */
    private function supportsEmbeddedIdentities(
        CandidateEvaluation $evaluation,
        array $embeddedReleases,
        array $embeddedReleaseGroups,
        array $embeddedRecordings,
    ): bool {
        $releaseGroupIds = array_values(array_unique(array_filter(array_values($embeddedReleases))));
        if (count($embeddedReleases) === 1 && ! $this->candidateSupportsRelease(
            $evaluation,
            (string) array_key_first($embeddedReleases),
        )) {
            return false;
        }
        if (count($embeddedReleases) > 1 && (
            count($releaseGroupIds) !== 1
            || ! $this->candidateSupportsReleaseGroup($evaluation, $releaseGroupIds[0])
        )) {
            return false;
        }
        foreach ($embeddedReleaseGroups as $releaseGroupId) {
            if (! $this->candidateSupportsReleaseGroup($evaluation, $releaseGroupId)) {
                return false;
            }
        }
        foreach ($embeddedRecordings as $recordingId) {
            if (! $this->candidateSupportsRecording($evaluation, $recordingId)) {
                return false;
            }
        }

        return true;
    }

    private function candidateSupportsRelease(CandidateEvaluation $evaluation, string $releaseId): bool
    {
        if ($evaluation->candidate->identity->releaseId === $releaseId) {
            return true;
        }

        return $this->signalsContainIdentity($evaluation, 'releaseId', $releaseId);
    }

    private function candidateSupportsReleaseGroup(CandidateEvaluation $evaluation, string $releaseGroupId): bool
    {
        if ($evaluation->candidate->identity->releaseGroupId === $releaseGroupId) {
            return true;
        }

        return $this->signalsContainIdentity($evaluation, 'releaseGroupId', $releaseGroupId);
    }

    private function candidateSupportsRecording(CandidateEvaluation $evaluation, string $recordingId): bool
    {
        if ($evaluation->candidate->identity->recordingId === $recordingId) {
            return true;
        }

        return $this->signalsContainIdentity($evaluation, 'recordingId', $recordingId);
    }

    /** @param 'recordingId'|'releaseId'|'releaseGroupId' $property */
    private function signalsContainIdentity(
        CandidateEvaluation $evaluation,
        string $property,
        string $expected,
    ): bool {
        foreach ($evaluation->candidate->signals as $signal) {
            if ($signal->identity->{$property} === $expected) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string|null> $embeddedReleases
     * @param  list<string>  $embeddedReleaseGroups
     */
    private function hasIncompatibleEmbeddedIdentities(array $embeddedReleases, array $embeddedReleaseGroups): bool
    {
        $releaseGroupIds = array_values(array_unique(array_filter(array_values($embeddedReleases))));
        if (count($embeddedReleases) > 1 && (count($releaseGroupIds) !== 1 || in_array(null, $embeddedReleases, true))) {
            return true;
        }

        $allValidatedGroups = array_values(array_unique([...$releaseGroupIds, ...$embeddedReleaseGroups]));

        return count($allValidatedGroups) > 1;
    }

    /**
     * @param  list<CandidateEvaluation>  $evaluations
     */
    private function runnerUpForScope(
        array $evaluations,
        CandidateEvaluation $best,
        ?IdentificationStatus $status,
    ): ?CandidateEvaluation {
        foreach (array_slice($evaluations, 1) as $evaluation) {
            $isSameScope = match ($status) {
                IdentificationStatus::AcceptedReleaseGroup => $this->sameReleaseGroup($best, $evaluation),
                IdentificationStatus::AcceptedRecording => $best->candidate->uniqueRecordingId() === $evaluation->candidate->uniqueRecordingId(),
                default => false,
            };
            if (! $isSameScope) {
                return $evaluation;
            }
        }

        return null;
    }

    private function sameReleaseGroup(CandidateEvaluation $left, CandidateEvaluation $right): bool
    {
        $releaseGroupId = $left->alignedIdentity->releaseGroupId;

        return $releaseGroupId !== null && $releaseGroupId === $right->alignedIdentity->releaseGroupId;
    }

    private function acceptedIdentity(IdentificationStatus $status, CandidateEvaluation $evaluation): CandidateIdentity
    {
        return match ($status) {
            IdentificationStatus::AcceptedRecording => new CandidateIdentity(recordingId: $evaluation->candidate->uniqueRecordingId()),
            IdentificationStatus::AcceptedReleaseGroup => new CandidateIdentity(releaseGroupId: $evaluation->alignedIdentity->releaseGroupId),
            IdentificationStatus::AcceptedEdition => new CandidateIdentity(
                releaseId: $evaluation->alignedIdentity->releaseId,
                releaseGroupId: $evaluation->alignedIdentity->releaseGroupId,
            ),
            default => throw new LogicException('Only accepted decisions have an accepted identity.'),
        };
    }

    /** @param list<CandidateSignal> $signals */
    private function hasExactSignal(array $signals, CandidateSignalKind $kind): bool
    {
        foreach ($signals as $signal) {
            if ($signal->kind === $kind && $signal->exact) {
                return true;
            }
        }

        return false;
    }

    /** @param list<CandidateSignal> $signals */
    private function hasSignal(array $signals, CandidateSignalKind $kind): bool
    {
        foreach ($signals as $signal) {
            if ($signal->kind === $kind) {
                return true;
            }
        }

        return false;
    }

    /** @param list<DecisionReason> $reasons
     * @param  list<CandidateSummary>  $summaries
     */
    private function decision(
        IdentificationStatus $status,
        CandidateEvaluation $best,
        ?CandidateIdentity $acceptedIdentity,
        array $reasons,
        array $summaries,
        ?int $runnerUpMargin,
    ): IdentificationDecision {
        return new IdentificationDecision(
            status: $status,
            score: $best->score,
            band: IdentificationBand::fromScore($best->score),
            acceptedIdentity: $acceptedIdentity,
            reasons: $reasons,
            candidates: $summaries,
            runnerUpMargin: $runnerUpMargin,
            algorithmVersion: $this->algorithmVersion,
            resolverVersion: $this->resolverVersion,
            normalizerVersion: $this->normalizerVersion,
            scorerVersion: $this->scorerVersion,
            policyVersion: $this->policyVersion,
        );
    }

    private function terminalDecision(IdentificationStatus $status, string $reason, ?string $error): IdentificationDecision
    {
        return new IdentificationDecision(
            status: $status,
            score: 0,
            band: IdentificationBand::Unresolved,
            acceptedIdentity: null,
            reasons: [new DecisionReason($reason, $error ?? str_replace('_', ' ', $reason))],
            candidates: [],
            runnerUpMargin: null,
            algorithmVersion: $this->algorithmVersion,
            resolverVersion: $this->resolverVersion,
            normalizerVersion: $this->normalizerVersion,
            scorerVersion: $this->scorerVersion,
            policyVersion: $this->policyVersion,
            operationalError: $error,
        );
    }
}
