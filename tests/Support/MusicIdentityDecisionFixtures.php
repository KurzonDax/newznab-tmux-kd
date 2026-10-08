<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Release;
use App\Models\ReleaseAudioEvidence;
use App\Services\MusicIdentity\Contracts\CandidateGenerator;
use App\Services\MusicIdentity\DTO\AcceptedMusicText;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateHypothesis;
use App\Services\MusicIdentity\DTO\CandidateIdentity;
use App\Services\MusicIdentity\DTO\CandidateMetadata;
use App\Services\MusicIdentity\DTO\CandidatePool;
use App\Services\MusicIdentity\DTO\CandidateSignal;
use App\Services\MusicIdentity\DTO\DecisionReason;
use App\Services\MusicIdentity\DTO\IdentificationDecision;
use App\Services\MusicIdentity\DTO\TrackEvidence;
use App\Services\MusicIdentity\Enums\CandidateSignalKind;
use App\Services\MusicIdentity\Enums\IdentificationBand;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\MusicIdentityResolver;
use App\Services\MusicIdentity\MusicIdentityRetryPolicy;
use App\Services\MusicIdentity\Persistence\IdentificationDecisionStore;
use Illuminate\Support\Facades\DB;

/**
 * Disposable releases with MusicBrainz identity decisions, resolved from frozen candidate pools:
 * an album accepted by its embedded release id, a recording accepted by its ISRC (its album
 * candidate stays unaccepted), and plain decisions of any status.
 */
trait MusicIdentityDecisionFixtures
{
    private const string RELEASE_ID = '11111111-1111-4111-8111-111111111111';

    private const string RELEASE_GROUP_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const string RECORDING_ID = '22222222-2222-4222-8222-000000000001';

    /** @param array<string, mixed> $attributes */
    private function release(string $name, ?int $musicInfoId = null, array $attributes = []): int
    {
        $values = Release::factory()->raw([
            'name' => $name, 'searchname' => $name, 'guid' => md5($name), 'categories_id' => 3040, 'groups_id' => 0,
            'fromname' => 'poster@example.test', 'musicinfo_id' => $musicInfoId, ...$attributes,
        ]);

        return DB::table('releases')->insertGetId(array_intersect_key($values, array_flip(DB::getSchemaBuilder()->getColumnListing('releases'))));
    }

    /** @param list<TrackEvidence> $tracks */
    private function resolveAndStore(int $releaseId, CandidateHypothesis $candidate, array $tracks, int $revision = 1): ReleaseAudioEvidence
    {
        $record = $this->evidenceRecord($releaseId, $revision);
        $evidence = new AudioEvidenceSet(
            evidenceId: (int) $record->id,
            evidenceHash: (string) $record->evidence_hash,
            releaseTitle: 'Example Artist - Example Album',
            albumTitle: 'Example Album',
            albumArtist: 'Example Artist',
            releaseYear: 2020,
            trackEvidence: $tracks,
            trackEvidenceListComplete: count($tracks) > 1,
        );
        $generator = new class(new CandidatePool([$candidate])) implements CandidateGenerator
        {
            public function __construct(private readonly CandidatePool $pool) {}

            public function generate(AudioEvidenceSet $evidence): CandidatePool
            {
                return $this->pool;
            }
        };
        $decision = (new MusicIdentityResolver($generator, algorithmVersion: (string) config('music-identity.algorithm_version')))->resolve($evidence);
        $this->assertNotNull($decision->acceptedText, 'the frozen candidate is accepted');
        (new IdentificationDecisionStore)->persist($releaseId, $evidence, $decision);

        return $record;
    }

    /** A decision of any status; the store keeps $text only for an accepted status. */
    private function persist(ReleaseAudioEvidence $evidence, IdentificationStatus $status, ?string $leaseToken = null, ?AcceptedMusicText $text = null): void
    {
        $retryable = $status === IdentificationStatus::RetryableError;
        (new IdentificationDecisionStore)->persist(
            releaseId: (int) $evidence->releases_id,
            evidence: new AudioEvidenceSet((int) $evidence->id, (string) $evidence->evidence_hash, null, null, null, null, []),
            decision: new IdentificationDecision(
                status: $status,
                score: $retryable ? 0 : 80,
                band: $retryable ? IdentificationBand::Unresolved : IdentificationBand::Suggestive,
                acceptedIdentity: null,
                reasons: [new DecisionReason('fixture', 'fixture')],
                candidates: [],
                runnerUpMargin: null,
                algorithmVersion: (string) config('music-identity.algorithm_version'),
                resolverVersion: 'resolver-v1',
                normalizerVersion: 'normalizer-v1',
                scorerVersion: 'whole-release-v1',
                policyVersion: 'shadow-v1',
                operationalError: $retryable ? 'mirror unavailable' : null,
                acceptedText: $text,
            ),
            nextAttemptAt: $retryable ? (new MusicIdentityRetryPolicy)->nextAttemptAt(0) : null,
            leaseToken: $leaseToken,
        );
    }

    private function evidenceRecord(int $releaseId, int $revision): ReleaseAudioEvidence
    {
        return ReleaseAudioEvidence::query()->create([
            'releases_id' => $releaseId,
            'revision' => $revision,
            'evidence_hash' => hash('sha256', $releaseId.':'.$revision),
            'schema_version' => 1,
            'provenance' => 'captured',
            'release_snapshot' => [],
            'archive_manifest_complete' => true,
            'nzb_manifest' => [],
            'archive_manifest' => [],
            'sidecar_manifest' => [],
            'captured_at' => now(),
        ]);
    }

    /** @return list<TrackEvidence> */
    private function albumEvidence(): array
    {
        return [
            new TrackEvidence(1, 'tag', 1, '01 - First Light.flac', 'First Light', 'Example Artist', 180_000, releaseId: self::RELEASE_ID),
            new TrackEvidence(2, 'tag', 2, '02 - Last Light.flac', 'Last Light', 'Example Artist', 210_000, releaseId: self::RELEASE_ID),
        ];
    }

    /** @return list<TrackEvidence> */
    private function recordingEvidence(): array
    {
        return [new TrackEvidence(1, 'tag', 1, '01.flac', 'Recorded Track', 'Example Artist', 180_000, isrc: 'USABC2012345')];
    }

    private function albumCandidate(): CandidateHypothesis
    {
        $identity = new CandidateIdentity(releaseId: self::RELEASE_ID, releaseGroupId: self::RELEASE_GROUP_ID);

        return $this->candidate(
            $identity,
            'Example Album',
            ['First Light', 'Last Light'],
            [new CandidateSignal(CandidateSignalKind::EmbeddedReleaseId, self::RELEASE_ID, 'tag-file:1', true, $identity)],
            ['Alias Album'],
        );
    }

    /** An ISRC accepts only the recording; its album candidate stays unaccepted. */
    private function recordingCandidate(): CandidateHypothesis
    {
        $identity = new CandidateIdentity(recordingId: self::RECORDING_ID, releaseId: self::RELEASE_ID, releaseGroupId: self::RELEASE_GROUP_ID);

        return $this->candidate(
            $identity,
            'Candidate Album',
            ['Recorded Track'],
            [new CandidateSignal(CandidateSignalKind::Isrc, 'USABC2012345', 'tag-file:1', true, $identity)],
            ['Candidate Alias'],
        );
    }

    /**
     * @param  list<string>  $titles
     * @param  list<CandidateSignal>  $signals
     * @param  list<string>  $aliases
     */
    private function candidate(CandidateIdentity $identity, string $album, array $titles, array $signals, array $aliases): CandidateHypothesis
    {
        $tracks = [];
        foreach ($titles as $index => $title) {
            $length = $index === 0 ? 180_000 : 210_000;
            $tracks[] = [
                'musicBrainzReleaseTrackId' => sprintf('33333333-3333-4333-8333-%012d', $index + 1),
                'title' => $title, 'position' => $index + 1, 'number' => (string) ($index + 1), 'lengthMs' => $length,
                'artistCredit' => 'Example Artist',
                'recording' => [
                    'recordingId' => sprintf('22222222-2222-4222-8222-%012d', $index + 1), 'title' => $title,
                    'artistCredit' => 'Example Artist', 'lengthMs' => $length, 'video' => false, 'isrcs' => [],
                    'releaseIds' => [self::RELEASE_ID], 'releaseGroupIds' => [self::RELEASE_GROUP_ID], 'providerScore' => null, 'sources' => ['fixture'],
                ],
            ];
        }

        return new CandidateHypothesis($identity, new CandidateMetadata([], [[
            'releaseId' => self::RELEASE_ID, 'title' => $album, 'artistCredit' => 'Example Artist', 'releaseGroupId' => self::RELEASE_GROUP_ID,
            'status' => 'Official', 'date' => '2020-01-01', 'country' => 'US', 'barcode' => null, 'labels' => [], 'aliases' => $aliases,
            'media' => [['position' => 1, 'title' => null, 'format' => 'CD', 'releaseTrackCount' => count($tracks), 'discIds' => [], 'releaseTracks' => $tracks]],
        ]], [[
            'releaseGroupId' => self::RELEASE_GROUP_ID, 'title' => $album, 'artistCredit' => 'Example Artist', 'primaryType' => 'Album',
            'secondaryTypes' => [], 'firstReleaseDate' => '2020-01-01', 'aliases' => $aliases,
        ]]), $signals);
    }
}
