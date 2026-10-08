<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity;

use App\Services\MusicIdentity\Contracts\AcousticFingerprintMatcher;
use App\Services\MusicIdentity\Contracts\MusicBrainzGateway;
use App\Services\MusicIdentity\DTO\AcousticFingerprintQuery;
use App\Services\MusicIdentity\DTO\AcousticRecording;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateHypothesis;
use App\Services\MusicIdentity\DTO\CandidateIdentifiers;
use App\Services\MusicIdentity\DTO\CandidateIdentity;
use App\Services\MusicIdentity\DTO\CandidateMetadata;
use App\Services\MusicIdentity\DTO\CandidatePool;
use App\Services\MusicIdentity\DTO\CandidateSignal;
use App\Services\MusicIdentity\DTO\TrackEvidence;
use App\Services\MusicIdentity\Enums\CandidateSignalKind;
use App\Services\MusicIdentity\Exceptions\AcousticFingerprintLookupException;
use App\Services\MusicIdentity\Exceptions\MusicBrainzGatewayException;
use Illuminate\Support\Facades\Log;

/**
 * Resolution step 7: the stored fingerprints of a release still unresolved or ambiguous are
 * looked up, and every MusicBrainz recording the service links to one becomes a fingerprint
 * signal in the candidate pool, hydrated through the MusicBrainz gateway. Each fingerprinted file
 * is its own provenance family, so one file supports at most one recording; only several files
 * converging on one release group can reach an album through the existing structural gates.
 */
final readonly class AcousticFingerprintCandidates
{
    public function __construct(
        private AcousticFingerprintMatcher $matcher,
        private MusicBrainzGateway $gateway,
    ) {}

    /** Whether a lookup is possible: a configured matcher and a fingerprint with a reliable whole-file duration. */
    public function applicable(AudioEvidenceSet $evidence): bool
    {
        return $this->lookups($evidence) !== [] && $this->matcher->available();
    }

    /**
     * @throws AcousticFingerprintLookupException when a retryable lookup failure outlasts its retries, or
     *                                            the step's time budget (kept well inside the work lease) is spent
     * @throws MusicBrainzGatewayException when hydrating a new candidate fails
     */
    public function supplement(AudioEvidenceSet $evidence, CandidatePool $pool): CandidatePool
    {
        /** @var array<string, array{identity: CandidateIdentity, metadata: CandidateMetadata, signals: list<CandidateSignal>}> $candidates */
        $candidates = [];
        foreach ($pool->candidates as $candidate) {
            $candidates[$candidate->identity->key()] = [
                'identity' => $candidate->identity,
                'metadata' => $candidate->metadata,
                'signals' => $candidate->signals,
            ];
        }

        $fingerprinted = [];
        $deadline = now()->addSeconds(max(1, (int) config('music-identity.acoustid.lookup_budget_seconds', 60)));
        foreach ($this->lookups($evidence) as [$trackEvidence, $query]) {
            if (now()->greaterThanOrEqualTo($deadline)) {
                throw new AcousticFingerprintLookupException('The AcoustID lookup budget was spent before every fingerprint was looked up.', retryable: true);
            }

            try {
                $matches = $this->matcher->lookup($query);
            } catch (AcousticFingerprintLookupException $exception) {
                if ($exception->retryable) {
                    throw $exception;
                }
                Log::warning('AcoustID rejected a fingerprint lookup; the file adds no fingerprint evidence.', [
                    'evidence_id' => $evidence->evidenceId,
                    'evidence_track_id' => $trackEvidence->evidenceTrackId,
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            $provenanceFamily = $trackEvidence->provenanceFamily
                ?? 'evidence:'.$evidence->evidenceId.':track:'.$trackEvidence->evidenceTrackId;
            foreach ($matches as $match) {
                foreach ($match->recordings as $recording) {
                    foreach ($this->identities($recording) as $identity) {
                        $key = $this->add($candidates, $identity, new CandidateSignal(
                            kind: CandidateSignalKind::Fingerprint,
                            value: $query->fingerprint,
                            provenanceFamily: $provenanceFamily,
                            exact: true,
                            identity: $identity,
                            providerScore: (int) round($match->score * 100),
                        ));
                        $fingerprinted[$key][$provenanceFamily] = true;
                    }
                }
            }
        }

        $this->hydrate($candidates, $fingerprinted);

        return new CandidatePool(array_values(array_map(
            static fn (array $candidate): CandidateHypothesis => new CandidateHypothesis(
                identity: $candidate['identity'],
                metadata: $candidate['metadata'],
                signals: $candidate['signals'],
            ),
            $candidates,
        )));
    }

    /** @return list<array{TrackEvidence, AcousticFingerprintQuery}> */
    private function lookups(AudioEvidenceSet $evidence): array
    {
        $lookups = [];
        $minimumDurationMs = max(1, (int) config('music-identity.acoustid.minimum_duration_milliseconds', 1_000));
        foreach ($evidence->trackEvidence as $trackEvidence) {
            if ($trackEvidence->fingerprint === null
                || $trackEvidence->fingerprintHash === null
                || $trackEvidence->fingerprintAlgorithm === null
                || $trackEvidence->fingerprintGeneratorVersion === null
                || $trackEvidence->durationMs === null
                || $trackEvidence->durationMs < $minimumDurationMs) {
                continue;
            }

            $lookups[] = [$trackEvidence, new AcousticFingerprintQuery(
                fingerprint: $trackEvidence->fingerprint,
                fingerprintHash: $trackEvidence->fingerprintHash,
                algorithm: $trackEvidence->fingerprintAlgorithm,
                generatorVersion: $trackEvidence->fingerprintGeneratorVersion,
                durationSeconds: (int) round($trackEvidence->durationMs / 1_000),
            )];
        }

        return $lookups;
    }

    /**
     * The same identities a MusicBrainz recording match produces: each listed release, each listed
     * release group, or the bare recording when the service lists neither.
     *
     * @return list<CandidateIdentity>
     */
    private function identities(AcousticRecording $recording): array
    {
        if ($recording->releaseGroupByRelease === [] && $recording->releaseGroupIds === []) {
            return [new CandidateIdentity(recordingId: $recording->recordingId)];
        }

        $identities = [];
        foreach ($recording->releaseGroupByRelease as $releaseId => $releaseGroupId) {
            $identities[] = new CandidateIdentity(
                recordingId: $recording->recordingId,
                releaseId: (string) $releaseId,
                releaseGroupId: $releaseGroupId ?? (count($recording->releaseGroupIds) === 1 ? $recording->releaseGroupIds[0] : null),
            );
        }
        foreach ($recording->releaseGroupIds as $releaseGroupId) {
            $identities[] = new CandidateIdentity(recordingId: $recording->recordingId, releaseGroupId: $releaseGroupId);
        }

        return $identities;
    }

    /**
     * @param  array<string, array{identity: CandidateIdentity, metadata: CandidateMetadata, signals: list<CandidateSignal>}>  $candidates
     * @return string the candidate key the signal joined
     */
    private function add(array &$candidates, CandidateIdentity $identity, CandidateSignal $signal): string
    {
        $key = $identity->key();
        $candidates[$key] ??= [
            'identity' => $identity->releaseId === null && $identity->releaseGroupId === null
                ? $identity
                : new CandidateIdentity(releaseId: $identity->releaseId, releaseGroupId: $identity->releaseGroupId),
            'metadata' => CandidateMetadata::empty(),
            'signals' => [],
        ];
        $candidates[$key]['signals'][] = $signal;

        return $key;
    }

    /**
     * Hydrates fingerprint candidates that have no MusicBrainz metadata yet, those supported by the
     * most fingerprinted files first, within the candidate-generation hydration limit.
     *
     * @param  array<string, array{identity: CandidateIdentity, metadata: CandidateMetadata, signals: list<CandidateSignal>}>  $candidates
     * @param  array<string, array<string, true>>  $fingerprinted
     */
    private function hydrate(array &$candidates, array $fingerprinted): void
    {
        $unhydrated = array_filter(
            $fingerprinted,
            fn (array $families, string $key): bool => $this->isEmpty($candidates[$key]['metadata']),
            ARRAY_FILTER_USE_BOTH,
        );
        uasort($unhydrated, static fn (array $left, array $right): int => count($right) <=> count($left));

        $limit = max(0, (int) config('music-identity.candidate_generation.hydration_limit', 8));
        foreach (array_slice(array_keys($unhydrated), 0, $limit) as $key) {
            $candidate = $candidates[$key];
            $candidate['metadata'] = $this->gateway->hydrate(new CandidateIdentifiers(
                recordingId: $candidate['identity']->recordingId,
                releaseId: $candidate['identity']->releaseId,
                releaseGroupId: $candidate['identity']->releaseGroupId,
            ));
            $candidates[$key] = $candidate;
        }
    }

    private function isEmpty(CandidateMetadata $metadata): bool
    {
        return $metadata->recordings === [] && $metadata->releases === [] && $metadata->releaseGroups === [];
    }
}
