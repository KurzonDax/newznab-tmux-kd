<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Matching;

use App\Services\MusicIdentity\DTO\AlignmentResult;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateEvaluation;
use App\Services\MusicIdentity\DTO\CandidateHypothesis;
use App\Services\MusicIdentity\DTO\CandidateIdentity;
use App\Services\MusicIdentity\DTO\CandidateSignal;
use App\Services\MusicIdentity\DTO\CandidateSummary;
use App\Services\MusicIdentity\Enums\CandidateSignalKind;

final readonly class WholeReleaseAlignmentScorer
{
    public function __construct(
        private ReleaseTrackSequenceAligner $aligner = new ReleaseTrackSequenceAligner,
        private CandidateTextNormalizer $normalizer = new CandidateTextNormalizer,
    ) {}

    public function score(AudioEvidenceSet $evidence, CandidateHypothesis $candidate): CandidateEvaluation
    {
        [$release, $alignment] = $this->bestReleaseAlignment($evidence, $candidate);
        $releaseGroup = $this->releaseGroup($candidate);
        $alignedIdentity = new CandidateIdentity(
            recordingId: $candidate->uniqueRecordingId(),
            releaseId: is_string($release['releaseId'] ?? null) ? $release['releaseId'] : $candidate->identity->releaseId,
            releaseGroupId: is_string($release['releaseGroupId'] ?? null)
                ? $release['releaseGroupId']
                : (is_string($releaseGroup['releaseGroupId'] ?? null)
                    ? $releaseGroup['releaseGroupId']
                    : $candidate->identity->releaseGroupId),
        );
        $titleAgreement = $this->bestTitleAgreement($evidence->albumTitle, $release, $releaseGroup);
        $artistAgreement = max(
            $this->textAgreement($evidence->albumArtist, $release['artistCredit'] ?? null),
            $this->textAgreement($evidence->albumArtist, $releaseGroup['artistCredit'] ?? null),
        );
        $distinctRecordingSupport = $candidate->distinctRecordingSupport();
        $independentRecordingSupport = $candidate->independentRecordingSupport();
        $exactContribution = $this->exactIdentifierContribution($candidate->signals);
        $metadataFeatures = $this->metadataFeatures($evidence, $release, $releaseGroup);
        $contributions = [
            'exact_identifier_agreement' => $exactContribution,
            'distinct_recording_support' => min(24, $independentRecordingSupport * 8),
            'candidate_retrieval_support' => $this->candidateRetrievalContribution($candidate->signals),
            'release_title_agreement' => (int) round($titleAgreement * 12),
            'artist_credit_agreement' => (int) round($artistAgreement * 9),
            'release_track_artist_credit_agreement' => (int) round($alignment->artistCreditAgreement * 4),
            'release_track_title_agreement' => (int) round($alignment->titleAgreement * 16),
            'ordered_sequence_agreement' => (int) round($alignment->orderAgreement * 5),
            'contiguous_subsequence_agreement' => (int) round($alignment->contiguousCoverage * 4),
            'per_disc_position_agreement' => (int) round($alignment->perDiscPositionAgreement * 4),
            'ordered_observed_coverage' => (int) round($alignment->observedCoverage * 9),
            'release_track_duration_agreement' => (int) round($alignment->durationAgreement * 8),
            'complete_track_count_agreement' => $this->trackCountContribution($evidence, $alignment),
            'edition_and_group_metadata' => array_sum(array_map(static fn (bool $matched): int => $matched ? 1 : 0, $metadataFeatures)),
            'unmatched_observed_penalty' => -min(12, $alignment->unmatchedObserved * 3),
            'unmatched_candidate_penalty' => $evidence->trackEvidenceListComplete === true
                ? -min(12, $alignment->unmatchedCandidate)
                : 0,
        ];
        $features = [
            'exact_identifier_contribution' => $exactContribution,
            'distinct_recording_support' => $distinctRecordingSupport,
            'independent_recording_support' => $independentRecordingSupport,
            'independent_evidence_support' => $candidate->independentEvidenceSupport(),
            'release_title_agreement' => round($titleAgreement, 4),
            'artist_credit_agreement' => round($artistAgreement, 4),
            'release_track_artist_credit_agreement' => $alignment->artistCreditAgreement,
            'release_track_artist_credit_comparison_count' => $alignment->artistCreditComparisonCount,
            'release_track_title_agreement' => $alignment->titleAgreement,
            'release_track_duration_agreement' => $alignment->durationAgreement,
            'duration_comparison_count' => $alignment->durationComparisonCount,
            'observed_coverage' => $alignment->observedCoverage,
            'candidate_coverage' => $alignment->candidateCoverage,
            'order_agreement' => $alignment->orderAgreement,
            'contiguous_coverage' => $alignment->contiguousCoverage,
            'per_disc_position_agreement' => $alignment->perDiscPositionAgreement,
            'observed_track_count' => $alignment->observedCount,
            'candidate_track_count' => $alignment->candidateCount,
            'track_evidence_list_complete' => $evidence->trackEvidenceListComplete,
            ...$metadataFeatures,
        ];
        $contradictions = $this->contradictions($evidence, $candidate, $release, $alignment, $artistAgreement);
        $score = max(0, min(100, array_sum($contributions) - (count($contradictions) * 35)));
        $displaySnapshot = [
            'title' => $release['title'] ?? $releaseGroup['title'] ?? null,
            'artistCredit' => $release['artistCredit'] ?? $releaseGroup['artistCredit'] ?? null,
            'date' => $release['date'] ?? $releaseGroup['firstReleaseDate'] ?? null,
            'country' => $release['country'] ?? null,
        ];
        $provenanceFamilies = array_values(array_unique(array_map(
            static fn (CandidateSignal $signal): string => $signal->provenanceFamily,
            $candidate->signals,
        )));
        $responseCacheKeys = $candidate->metadata->responseCacheKeys;
        foreach ($candidate->signals as $signal) {
            array_push($responseCacheKeys, ...$signal->responseCacheKeys);
        }
        $responseCacheKeys = array_values(array_unique($responseCacheKeys));
        $summary = new CandidateSummary(
            identity: $alignedIdentity,
            score: $score,
            displaySnapshot: $displaySnapshot,
            featureVector: $features,
            scoreContributions: $contributions,
            contradictions: $contradictions,
            provenanceFamilies: $provenanceFamilies,
            responseCacheKeys: $responseCacheKeys,
        );

        return new CandidateEvaluation(
            candidate: $candidate,
            score: $score,
            features: $features,
            contributions: $contributions,
            contradictions: $contradictions,
            alignment: $alignment,
            alignedIdentity: $alignedIdentity,
            summary: $summary,
        );
    }

    /** @return array{array<string, mixed>|null, AlignmentResult} */
    private function bestReleaseAlignment(AudioEvidenceSet $evidence, CandidateHypothesis $candidate): array
    {
        $bestRelease = null;
        $bestAlignment = AlignmentResult::empty(count($evidence->trackEvidence));
        $bestQuality = -1.0;
        foreach ($candidate->metadata->releases as $release) {
            if ($candidate->identity->releaseId !== null
                && $release['releaseId'] !== $candidate->identity->releaseId) {
                continue;
            }
            if ($candidate->identity->releaseId === null
                && $candidate->identity->releaseGroupId !== null
                && $release['releaseGroupId'] !== $candidate->identity->releaseGroupId) {
                continue;
            }
            $alignment = $this->aligner->align($evidence, $release);
            $quality = ($alignment->titleAgreement * 0.55) + ($alignment->observedCoverage * 0.3) + ($alignment->durationAgreement * 0.15);
            if ($quality > $bestQuality) {
                $bestQuality = $quality;
                $bestRelease = $release;
                $bestAlignment = $alignment;
            }
        }

        return [$bestRelease, $bestAlignment];
    }

    /** @return array<string, mixed>|null */
    private function releaseGroup(CandidateHypothesis $candidate): ?array
    {
        foreach ($candidate->metadata->releaseGroups as $releaseGroup) {
            if ($candidate->identity->releaseGroupId === null || $releaseGroup['releaseGroupId'] === $candidate->identity->releaseGroupId) {
                return $releaseGroup;
            }
        }

        return $candidate->metadata->releaseGroups[0] ?? null;
    }

    /** @param list<CandidateSignal> $signals */
    private function exactIdentifierContribution(array $signals): int
    {
        $best = 0;
        foreach ($signals as $signal) {
            if (! $signal->exact) {
                continue;
            }
            $best = max($best, match ($signal->kind) {
                CandidateSignalKind::EmbeddedReleaseId => 28,
                CandidateSignalKind::DiscId, CandidateSignalKind::DiscToc => 27,
                CandidateSignalKind::EmbeddedReleaseGroupId => 24,
                CandidateSignalKind::Barcode, CandidateSignalKind::CatalogNumber => 18,
                CandidateSignalKind::EmbeddedReleaseTrackId => 12,
                CandidateSignalKind::EmbeddedRecordingId, CandidateSignalKind::Fingerprint, CandidateSignalKind::Isrc => 11,
                default => 0,
            });
        }

        return $best;
    }

    /** @param list<CandidateSignal> $signals */
    private function candidateRetrievalContribution(array $signals): int
    {
        foreach ($signals as $signal) {
            if (in_array($signal->kind, [CandidateSignalKind::ReleaseSearch, CandidateSignalKind::TrackEvidenceSearch], true)) {
                return 6;
            }
        }

        return 0;
    }

    private function trackCountContribution(AudioEvidenceSet $evidence, AlignmentResult $alignment): int
    {
        if ($evidence->trackEvidenceListComplete !== true) {
            return 0;
        }

        return match (abs($alignment->observedCount - $alignment->candidateCount)) {
            0 => 5,
            1 => 3,
            default => 0,
        };
    }

    /** @param array<string, mixed>|null $release
     * @param  array<string, mixed>|null  $releaseGroup
     * @return array<string, bool>
     */
    private function metadataFeatures(AudioEvidenceSet $evidence, ?array $release, ?array $releaseGroup): array
    {
        $editionDate = $release['date'] ?? null;
        $originalDate = $releaseGroup['firstReleaseDate'] ?? null;
        $media = is_array($release['media'] ?? null) ? $release['media'] : [];
        $mediaFormats = array_values(array_filter(array_map(
            static fn (array $medium): mixed => $medium['format'] ?? null,
            $media,
        ), 'is_string'));
        $candidateSecondaryTypes = is_array($releaseGroup['secondaryTypes'] ?? null) ? $releaseGroup['secondaryTypes'] : [];

        return [
            'edition_date_agreement' => $evidence->releaseYear !== null && is_string($editionDate) && str_starts_with($editionDate, (string) $evidence->releaseYear),
            'original_date_agreement' => $evidence->releaseYear !== null && is_string($originalDate) && str_starts_with($originalDate, (string) $evidence->releaseYear),
            'country_agreement' => $evidence->country !== null && $this->sameText($evidence->country, $release['country'] ?? null),
            'media_format_agreement' => $evidence->mediaFormat !== null && $this->containsSameText($evidence->mediaFormat, $mediaFormats),
            'medium_count_agreement' => $evidence->trackEvidenceListComplete === true && $evidence->mediumCount !== null && $evidence->mediumCount === count($media),
            'release_status_agreement' => $evidence->releaseStatus !== null && $this->sameText($evidence->releaseStatus, $release['status'] ?? null),
            'primary_type_agreement' => $evidence->primaryType !== null && $this->sameText($evidence->primaryType, $releaseGroup['primaryType'] ?? null),
            'secondary_type_agreement' => $evidence->secondaryTypes !== [] && $this->hasTextIntersection($evidence->secondaryTypes, $candidateSecondaryTypes),
        ];
    }

    /** @param array<string, mixed>|null $release
     * @return list<string>
     */
    private function contradictions(
        AudioEvidenceSet $evidence,
        CandidateHypothesis $candidate,
        ?array $release,
        AlignmentResult $alignment,
        float $artistAgreement,
    ): array {
        $contradictions = [];

        $fingerprints = array_values(array_filter(array_map(
            static fn ($trackEvidence): ?string => $trackEvidence->fingerprint,
            $evidence->trackEvidence,
        )));
        foreach ($candidate->signals as $signal) {
            if ($signal->kind === CandidateSignalKind::Fingerprint && $signal->exact && $fingerprints !== [] && ! in_array($signal->value, $fingerprints, true)) {
                $contradictions[] = 'incompatible_fingerprint';
                break;
            }
        }

        if ($evidence->trackEvidenceListComplete === true && $alignment->observedCount >= 2 && (
            $alignment->observedCoverage < 0.5
            || $alignment->candidateCount === 0
            || ($alignment->candidateCount > ($alignment->observedCount * 1.5) && $alignment->candidateCoverage < 0.65)
        )) {
            $contradictions[] = 'impossible_complete_track_structure';
        }
        if ($evidence->trackEvidenceListComplete === true && $alignment->durationComparisonCount >= 2 && $alignment->durationAgreement < 0.2) {
            $contradictions[] = 'impossible_complete_duration_structure';
        }

        $candidateArtist = $release['artistCredit'] ?? null;
        if ($evidence->albumArtist !== null
            && $candidateArtist !== null
            && $artistAgreement < 0.4
            && ! $this->isCompilationArtist($evidence->albumArtist)
            && ! $this->isCompilationArtist((string) $candidateArtist)
            && ! $this->isFeaturedCreditVariant($evidence->albumArtist, (string) $candidateArtist)) {
            $contradictions[] = 'strong_album_artist_conflict';
        }

        return array_values(array_unique($contradictions));
    }

    private function isCompilationArtist(string $artist): bool
    {
        $normalized = $this->normalizer->normalize($artist);

        return in_array($normalized, ['various artists', 'various', 'va'], true);
    }

    private function isFeaturedCreditVariant(string $left, string $right): bool
    {
        $hasFeaturedConnector = preg_match('/\b(?:feat|featuring|ft)\b/iu', $left.' '.$right) === 1;
        $left = $this->normalizer->normalize($left) ?? '';
        $right = $this->normalizer->normalize($right) ?? '';
        if (! $hasFeaturedConnector) {
            return false;
        }

        return str_contains(' '.$left.' ', ' '.$right.' ')
            || str_contains(' '.$right.' ', ' '.$left.' ');
    }

    private function sameText(?string $left, mixed $right): bool
    {
        return is_string($right) && $this->normalizer->normalize($left) === $this->normalizer->normalize($right);
    }

    /** @param list<mixed> $values */
    private function containsSameText(string $needle, array $values): bool
    {
        foreach ($values as $value) {
            if ($this->sameText($needle, $value)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $left
     * @param  list<mixed>  $right
     */
    private function hasTextIntersection(array $left, array $right): bool
    {
        foreach ($left as $needle) {
            if ($this->containsSameText($needle, $right)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed>|null $release
     * @param  array<string, mixed>|null  $releaseGroup
     */
    private function bestTitleAgreement(?string $evidenceTitle, ?array $release, ?array $releaseGroup): float
    {
        $candidateTitles = array_values(array_filter([
            $release['title'] ?? null,
            $releaseGroup['title'] ?? null,
            ...($release['aliases'] ?? []),
            ...($releaseGroup['aliases'] ?? []),
        ], 'is_string'));
        $agreements = array_map(fn (string $title): float => $this->textAgreement($evidenceTitle, $title), $candidateTitles);

        return $agreements === [] ? 0.0 : max($agreements);
    }

    private function textAgreement(?string $left, mixed $right): float
    {
        if (! is_string($right)) {
            return 0.0;
        }
        $left = $this->normalizer->normalize($left);
        $right = $this->normalizer->normalize($right);
        if ($left === null || $right === null) {
            return 0.0;
        }
        if ($left === $right) {
            return 1.0;
        }
        $leftTokens = array_unique(explode(' ', $left));
        $rightTokens = array_unique(explode(' ', $right));
        $union = array_unique([...$leftTokens, ...$rightTokens]);

        return $union === [] ? 0.0 : count(array_intersect($leftTokens, $rightTokens)) / count($union);
    }
}
