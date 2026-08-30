<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity;

use App\Services\MusicIdentity\Contracts\MusicBrainzGateway;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateHypothesis;
use App\Services\MusicIdentity\DTO\CandidateIdentifiers;
use App\Services\MusicIdentity\DTO\CandidateIdentity;
use App\Services\MusicIdentity\DTO\CandidateMetadata;
use App\Services\MusicIdentity\DTO\CandidatePool;
use App\Services\MusicIdentity\DTO\CandidateSignal;
use App\Services\MusicIdentity\DTO\ExactRecordingIdentifierQuery;
use App\Services\MusicIdentity\DTO\ExactReleaseIdentifierQuery;
use App\Services\MusicIdentity\DTO\RecordingCandidates;
use App\Services\MusicIdentity\DTO\RecordingQuery;
use App\Services\MusicIdentity\DTO\ReleaseCandidates;
use App\Services\MusicIdentity\DTO\ReleaseQuery;
use App\Services\MusicIdentity\Enums\CandidateSignalKind;
use App\Services\MusicIdentity\Matching\CandidateTextNormalizer;
use App\Services\MusicIdentity\Matching\DistinctiveTrackEvidenceSelector;
use App\Services\MusicIdentity\Support\MusicIdentityValueNormalizer;

final class MusicCandidateGenerator
{
    private readonly CandidateTextNormalizer $normalizer;

    private readonly DistinctiveTrackEvidenceSelector $trackEvidenceSelector;

    public function __construct(
        private readonly MusicBrainzGateway $gateway,
        ?CandidateTextNormalizer $normalizer = null,
        ?DistinctiveTrackEvidenceSelector $trackEvidenceSelector = null,
    ) {
        $this->normalizer = $normalizer ?? new CandidateTextNormalizer;
        $this->trackEvidenceSelector = $trackEvidenceSelector ?? new DistinctiveTrackEvidenceSelector($this->normalizer);
    }

    public function generate(AudioEvidenceSet $evidence): CandidatePool
    {
        /**
         * @var array<string, array{
         *     identity: CandidateIdentity,
         *     metadata: CandidateMetadata|null,
         *     signals: list<CandidateSignal>
         * }> $accumulators
         */
        $accumulators = [];
        $validatedArtistIds = $this->collectExactIdentifierCandidates($evidence, $accumulators);
        $trackEvidenceLimit = max(0, (int) config('music-identity.candidate_generation.distinctive_track_evidence_limit', 4));
        $selectedTrackEvidence = $this->trackEvidenceSelector->select($evidence->trackEvidence, $trackEvidenceLimit);

        foreach ($selectedTrackEvidence as $trackEvidence) {
            $title = $this->normalizer->normalize($trackEvidence->title ?? $trackEvidence->rawFilename);
            if ($title === null) {
                continue;
            }

            $query = new RecordingQuery(
                title: $title,
                artist: $this->normalizer->normalize($trackEvidence->artist ?? $evidence->albumArtist),
                releaseTitle: $this->normalizer->normalize($evidence->albumTitle),
                durationMs: $trackEvidence->durationMs,
                limit: max(1, (int) config('music-identity.candidate_generation.provider_result_limit', 15)),
                artistId: $validatedArtistIds[$trackEvidence->evidenceTrackId] ?? null,
                fuzzy: true,
            );
            $matches = $this->gateway->candidatesFor($query);
            $this->accumulateRecordingMatches(
                $accumulators,
                $matches,
                CandidateSignalKind::TrackEvidenceSearch,
                $title,
                $evidence->provenanceFamilyFor($trackEvidence),
                false,
            );
        }

        $albumTitle = $this->normalizer->normalize($evidence->albumTitle);
        $uniqueArtistIds = array_values(array_unique($validatedArtistIds));
        $albumArtistId = count($uniqueArtistIds) === 1 ? $uniqueArtistIds[0] : null;
        if ($albumTitle !== null) {
            $this->accumulateReleaseMatches(
                $accumulators,
                $this->gateway->releaseCandidatesFor(new ReleaseQuery(
                    title: $albumTitle,
                    artist: $this->normalizer->normalize($evidence->albumArtist),
                    artistId: $albumArtistId,
                    year: $evidence->releaseYear,
                    fuzzy: true,
                    limit: max(1, (int) config('music-identity.candidate_generation.provider_result_limit', 15)),
                )),
                CandidateSignalKind::ReleaseSearch,
                $albumTitle,
                $evidence->albumProvenanceFamily(),
                false,
            );
        }

        uasort($accumulators, static function (array $left, array $right): int {
            $leftRecordings = count(array_unique(array_filter(array_map(
                static fn (CandidateSignal $signal): ?string => $signal->identity->recordingId,
                $left['signals'],
            ))));
            $rightRecordings = count(array_unique(array_filter(array_map(
                static fn (CandidateSignal $signal): ?string => $signal->identity->recordingId,
                $right['signals'],
            ))));
            $leftFamilies = count(array_unique(array_map(
                static fn (CandidateSignal $signal): string => $signal->provenanceFamily,
                $left['signals'],
            )));
            $rightFamilies = count(array_unique(array_map(
                static fn (CandidateSignal $signal): string => $signal->provenanceFamily,
                $right['signals'],
            )));

            return $rightRecordings <=> $leftRecordings
                ?: $rightFamilies <=> $leftFamilies;
        });

        $hydrationLimit = max(0, (int) config('music-identity.candidate_generation.hydration_limit', 8));
        $candidates = [];
        foreach (array_values($accumulators) as $rank => $candidate) {
            $metadata = $candidate['metadata'];
            if ($metadata === null && $rank < $hydrationLimit) {
                $metadata = $this->gateway->hydrate(new CandidateIdentifiers(
                    recordingId: $candidate['identity']->recordingId,
                    releaseId: $candidate['identity']->releaseId,
                    releaseGroupId: $candidate['identity']->releaseGroupId,
                ));
            }
            $candidates[] = new CandidateHypothesis(
                identity: $candidate['identity'],
                metadata: $metadata ?? CandidateMetadata::empty(),
                signals: $candidate['signals'],
            );
        }

        return new CandidatePool($candidates);
    }

    /**
     * @param  array<string, array{identity: CandidateIdentity, metadata: CandidateMetadata|null, signals: list<CandidateSignal>}>  $accumulators
     */
    private function accumulate(
        array &$accumulators,
        CandidateIdentity $identity,
        CandidateSignal $signal,
        ?CandidateMetadata $metadata = null,
    ): void {
        $key = $identity->key();
        $accumulators[$key] ??= [
            'identity' => $identity,
            'metadata' => $metadata,
            'signals' => [],
        ];
        if ($accumulators[$key]['identity']->releaseGroupId === null && $identity->releaseGroupId !== null) {
            $accumulators[$key]['identity'] = $identity;
        }
        $accumulators[$key]['metadata'] ??= $metadata;
        $accumulators[$key]['signals'][] = $signal;
    }

    /**
     * @param  array<string, array{identity: CandidateIdentity, metadata: CandidateMetadata|null, signals: list<CandidateSignal>}>  $accumulators
     * @return array<int, string>
     */
    private function collectExactIdentifierCandidates(AudioEvidenceSet $evidence, array &$accumulators): array
    {
        $remaining = max(0, (int) config('music-identity.candidate_generation.exact_identifier_limit', 12));
        $hydrated = [];
        $queried = [];
        $validatedArtistIds = [];

        foreach ($evidence->trackEvidence as $trackEvidence) {
            $releaseId = MusicIdentityValueNormalizer::musicBrainzId($trackEvidence->releaseId);
            $releaseGroupId = MusicIdentityValueNormalizer::musicBrainzId($trackEvidence->releaseGroupId);
            $canonicalReleaseId = null;
            $canonicalReleaseGroupId = null;
            $releaseMetadata = null;

            $releaseKey = $releaseId === null ? null : 'release:'.$releaseId;
            if ($releaseKey !== null && (isset($hydrated[$releaseKey]) || $remaining > 0)) {
                $key = $releaseKey;
                if (! isset($hydrated[$key])) {
                    $hydrated[$key] = $this->gateway->hydrate(new CandidateIdentifiers(releaseId: $releaseId));
                    $remaining--;
                }

                $releaseMetadata = $hydrated[$key];
                $canonicalReleaseId = $releaseMetadata->releases[0]['releaseId'] ?? null;
                $canonicalReleaseGroupId = $releaseMetadata->releases[0]['releaseGroupId'] ?? null;
                if ($canonicalReleaseId !== null) {
                    $this->accumulate(
                        $accumulators,
                        new CandidateIdentity(
                            releaseId: $canonicalReleaseId,
                            releaseGroupId: $canonicalReleaseGroupId,
                        ),
                        new CandidateSignal(
                            kind: CandidateSignalKind::EmbeddedReleaseId,
                            value: $canonicalReleaseId,
                            provenanceFamily: $evidence->provenanceFamilyFor($trackEvidence),
                            exact: true,
                            identity: new CandidateIdentity(
                                releaseId: $canonicalReleaseId,
                                releaseGroupId: $canonicalReleaseGroupId,
                            ),
                        ),
                        $releaseMetadata,
                    );
                }
            }

            $releaseGroupKey = $releaseGroupId === null ? null : 'release-group:'.$releaseGroupId;
            if ($releaseGroupKey !== null && (isset($hydrated[$releaseGroupKey]) || $remaining > 0)) {
                $key = $releaseGroupKey;
                if (! isset($hydrated[$key])) {
                    $hydrated[$key] = $this->gateway->hydrate(new CandidateIdentifiers(releaseGroupId: $releaseGroupId));
                    $remaining--;
                }

                $releaseGroupMetadata = $hydrated[$key];
                $validatedReleaseGroupId = $releaseGroupMetadata->releaseGroups[0]['releaseGroupId'] ?? null;
                if ($validatedReleaseGroupId !== null) {
                    $agreesWithRelease = $canonicalReleaseId !== null
                        && $validatedReleaseGroupId === $canonicalReleaseGroupId;
                    $identity = $agreesWithRelease
                        ? new CandidateIdentity(
                            releaseId: $canonicalReleaseId,
                            releaseGroupId: $validatedReleaseGroupId,
                        )
                        : new CandidateIdentity(releaseGroupId: $validatedReleaseGroupId);
                    $this->accumulate(
                        $accumulators,
                        $identity,
                        new CandidateSignal(
                            kind: CandidateSignalKind::EmbeddedReleaseGroupId,
                            value: $validatedReleaseGroupId,
                            provenanceFamily: $evidence->provenanceFamilyFor($trackEvidence),
                            exact: true,
                            identity: $identity,
                        ),
                        $agreesWithRelease ? $releaseMetadata : $releaseGroupMetadata,
                    );
                }
            }

            $artistId = MusicIdentityValueNormalizer::musicBrainzId($trackEvidence->artistId);
            $artistKey = $artistId === null ? null : 'artist:'.$artistId;
            if ($artistKey !== null && (isset($hydrated[$artistKey]) || $remaining > 0)) {
                if (! isset($hydrated[$artistKey])) {
                    $hydrated[$artistKey] = $this->gateway->hydrate(new CandidateIdentifiers(artistId: $artistId));
                    $remaining--;
                }
                $canonicalArtistId = $hydrated[$artistKey]->artists[0]['artistId'] ?? null;
                if ($canonicalArtistId !== null) {
                    $validatedArtistIds[$trackEvidence->evidenceTrackId] = $canonicalArtistId;
                }
            }

            $exactQueries = array_values(array_filter([
                $this->exactQuery(
                    CandidateSignalKind::EmbeddedRecordingId,
                    MusicIdentityValueNormalizer::musicBrainzId($trackEvidence->recordingId),
                    static fn (string $value): RecordingQuery => new RecordingQuery(recordingId: $value),
                ),
                $this->exactQuery(
                    CandidateSignalKind::EmbeddedReleaseTrackId,
                    MusicIdentityValueNormalizer::musicBrainzId($trackEvidence->musicBrainzReleaseTrackId),
                    static fn (string $value): RecordingQuery => new RecordingQuery(musicBrainzReleaseTrackId: $value),
                ),
                $this->exactQuery(
                    CandidateSignalKind::Isrc,
                    MusicIdentityValueNormalizer::text($trackEvidence->isrc, uppercase: true),
                    static fn (string $value): RecordingQuery => new RecordingQuery(isrc: $value),
                ),
                $this->exactQuery(
                    CandidateSignalKind::DiscId,
                    MusicIdentityValueNormalizer::text($trackEvidence->discId),
                    static fn (string $value): RecordingQuery => new RecordingQuery(discId: $value),
                ),
                $this->exactQuery(
                    CandidateSignalKind::DiscToc,
                    MusicIdentityValueNormalizer::text($trackEvidence->discToc),
                    static fn (string $value): RecordingQuery => new RecordingQuery(discToc: $value),
                ),
            ]));
            foreach ($exactQueries as $exactQuery) {
                if ($remaining === 0) {
                    continue;
                }

                $queryKey = $exactQuery->signalKind->value.':'.$exactQuery->value;
                if (isset($queried[$queryKey])) {
                    continue;
                }
                $queried[$queryKey] = true;
                $remaining--;
                $this->accumulateRecordingMatches(
                    $accumulators,
                    $this->gateway->candidatesFor($exactQuery->query),
                    $exactQuery->signalKind,
                    $exactQuery->value,
                    $evidence->provenanceFamilyFor($trackEvidence),
                    true,
                );
            }

            $releaseQueries = array_values(array_filter([
                $this->exactReleaseQuery(
                    CandidateSignalKind::Barcode,
                    MusicIdentityValueNormalizer::text($trackEvidence->barcode),
                    static fn (string $value): ReleaseQuery => new ReleaseQuery(barcode: $value),
                ),
                $this->exactReleaseQuery(
                    CandidateSignalKind::CatalogNumber,
                    MusicIdentityValueNormalizer::text($trackEvidence->catalogNumber),
                    static fn (string $value): ReleaseQuery => new ReleaseQuery(
                        catalogNumber: $value,
                        label: $trackEvidence->label,
                    ),
                ),
            ]));
            foreach ($releaseQueries as $releaseQuery) {
                if ($remaining === 0) {
                    continue;
                }

                $queryKey = $releaseQuery->signalKind->value.':'.$releaseQuery->value;
                if (isset($queried[$queryKey])) {
                    continue;
                }
                $queried[$queryKey] = true;
                $remaining--;
                $this->accumulateReleaseMatches(
                    $accumulators,
                    $this->gateway->releaseCandidatesFor($releaseQuery->query),
                    $releaseQuery->signalKind,
                    $releaseQuery->value,
                    $evidence->provenanceFamilyFor($trackEvidence),
                    true,
                );
            }
        }

        return $validatedArtistIds;
    }

    /**
     * @param  array<string, array{identity: CandidateIdentity, metadata: CandidateMetadata|null, signals: list<CandidateSignal>}>  $accumulators
     */
    private function accumulateReleaseMatches(
        array &$accumulators,
        ReleaseCandidates $matches,
        CandidateSignalKind $signalKind,
        string $signalValue,
        string $provenanceFamily,
        bool $exact,
    ): void {
        foreach ($matches->releases as $release) {
            $this->accumulate(
                $accumulators,
                new CandidateIdentity(
                    releaseId: $release['releaseId'],
                    releaseGroupId: $release['releaseGroupId'],
                ),
                new CandidateSignal(
                    kind: $signalKind,
                    value: $signalValue,
                    provenanceFamily: $provenanceFamily,
                    exact: $exact,
                    identity: new CandidateIdentity(
                        releaseId: $release['releaseId'],
                        releaseGroupId: $release['releaseGroupId'],
                    ),
                    providerScore: $release['providerScore'],
                ),
            );
        }
    }

    /**
     * @param  array<string, array{identity: CandidateIdentity, metadata: CandidateMetadata|null, signals: list<CandidateSignal>}>  $accumulators
     */
    private function accumulateRecordingMatches(
        array &$accumulators,
        RecordingCandidates $matches,
        CandidateSignalKind $signalKind,
        string $signalValue,
        string $provenanceFamily,
        bool $exact,
    ): void {
        foreach ($matches->recordings as $recording) {
            $releaseIds = $recording['releaseIds'];
            $releaseGroupIds = $recording['releaseGroupIds'];
            if ($releaseIds === [] && $releaseGroupIds === []) {
                $this->accumulate(
                    $accumulators,
                    new CandidateIdentity(recordingId: (string) $recording['recordingId']),
                    new CandidateSignal(
                        kind: $signalKind,
                        value: $signalValue,
                        provenanceFamily: $provenanceFamily,
                        exact: $exact,
                        identity: new CandidateIdentity(recordingId: (string) $recording['recordingId']),
                        providerScore: $recording['providerScore'],
                    ),
                );

                continue;
            }

            foreach ($releaseIds as $releaseId) {
                $releaseGroupId = count($releaseGroupIds) === 1 ? $releaseGroupIds[0] : null;
                $this->accumulate(
                    $accumulators,
                    new CandidateIdentity(
                        releaseId: $releaseId,
                        releaseGroupId: $releaseGroupId,
                    ),
                    new CandidateSignal(
                        kind: $signalKind,
                        value: $signalValue,
                        provenanceFamily: $provenanceFamily,
                        exact: $exact,
                        identity: new CandidateIdentity(
                            recordingId: (string) $recording['recordingId'],
                            releaseId: $releaseId,
                            releaseGroupId: $releaseGroupId,
                        ),
                        providerScore: $recording['providerScore'],
                    ),
                );
            }

            foreach ($releaseGroupIds as $releaseGroupId) {
                $this->accumulate(
                    $accumulators,
                    new CandidateIdentity(releaseGroupId: $releaseGroupId),
                    new CandidateSignal(
                        kind: $signalKind,
                        value: $signalValue,
                        provenanceFamily: $provenanceFamily,
                        exact: $exact,
                        identity: new CandidateIdentity(
                            recordingId: (string) $recording['recordingId'],
                            releaseGroupId: $releaseGroupId,
                        ),
                        providerScore: $recording['providerScore'],
                    ),
                );
            }
        }
    }

    /** @param callable(string): RecordingQuery $queryFactory */
    private function exactQuery(
        CandidateSignalKind $signalKind,
        ?string $value,
        callable $queryFactory,
    ): ?ExactRecordingIdentifierQuery {
        if ($value === null) {
            return null;
        }

        return new ExactRecordingIdentifierQuery($signalKind, $value, $queryFactory($value));
    }

    /** @param callable(string): ReleaseQuery $queryFactory */
    private function exactReleaseQuery(
        CandidateSignalKind $signalKind,
        ?string $value,
        callable $queryFactory,
    ): ?ExactReleaseIdentifierQuery {
        if ($value === null) {
            return null;
        }

        return new ExactReleaseIdentifierQuery($signalKind, $value, $queryFactory($value));
    }
}
