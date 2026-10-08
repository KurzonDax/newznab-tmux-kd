<?php

declare(strict_types=1);

namespace Tests\Feature\MusicIdentity;

use App\Facades\Search;
use App\Models\Release;
use App\Models\ReleaseAudioEvidence;
use App\Services\MusicIdentity\Contracts\CandidateGenerator;
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
use App\Services\MusicIdentity\Persistence\MusicIdentityLeaseManager;
use App\Services\Search\Support\ReleaseIndexProjection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * A release's search document carries the MusicBrainz text of its current accepted decision
 * (issue #308): album title and aliases, artist credit and track titles for an accepted album; the
 * recording title and its artist credit for an accepted recording; nothing for any other decision,
 * which leaves the legacy musicinfo text as it is. Provider responses are frozen candidate pools.
 */
final class MusicIdentitySearchDocumentTest extends TestCase
{
    private const string RELEASE_ID = '11111111-1111-4111-8111-111111111111';

    private const string RELEASE_GROUP_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const string RECORDING_ID = '22222222-2222-4222-8222-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'music-identity.algorithm_version' => 'music-identity-v1',
        ]);
        DB::purge();
        DB::reconnect();
        Carbon::setTestNow('2026-10-08 12:00:00');
        Search::spy();

        foreach ([
            'releases', 'usenet_groups', 'categories', 'root_categories', 'movieinfo', 'musicinfo', 'consoleinfo', 'gamesinfo',
            'bookinfo', 'videos', 'tv_episodes', 'release_nfos', 'video_data', 'media_infos', 'release_files', 'audio_data',
            'release_subtitles', 'anidb_titles', 'media_info_probes', 'media_info_tracks', 'release_audio_tags',
            'release_audio_evidence', 'release_music_identifications', 'release_music_candidate_attempts',
        ] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        DB::table('root_categories')->insert(['id' => 3000, 'title' => 'Audio']);
        DB::table('categories')->insert(['id' => 3040, 'title' => 'Lossless', 'root_categories_id' => 3000]);
        DB::table('musicinfo')->insert(['id' => 7, 'title' => 'Legacy Album', 'artist' => 'Legacy Artist']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function a_recording_only_acceptance_adds_its_recording_title_and_no_album_text(): void
    {
        $unlinked = $this->release('Unlinked.Release-GRP');
        $legacy = $this->release('Legacy.Linked.Release-GRP', musicInfoId: 7);
        foreach ([$unlinked, $legacy] as $releaseId) {
            $this->resolveAndStore($releaseId, $this->recordingCandidate(), $this->recordingEvidence());
        }

        $unlinkedDocument = $this->document($unlinked);
        $legacyDocument = $this->document($legacy);

        foreach ([$unlinkedDocument, $legacyDocument] as $document) {
            $this->assertStringContainsString('Recorded Track', $document['music_tracks']);
            $this->assertSame('Example Artist', $document['artist']);
            $this->assertStringNotContainsString('Candidate Album', json_encode($document, JSON_THROW_ON_ERROR));
        }
        $this->assertSame('', $unlinkedDocument['album_title']);
        $this->assertSame('Legacy Album', $legacyDocument['album_title'], 'a recording acceptance leaves the legacy album match');
    }

    #[Test]
    public function an_accepted_album_puts_its_title_aliases_artist_and_tracks_in_the_document(): void
    {
        $releaseId = $this->release('Obfuscated.Album-GRP', musicInfoId: 7);

        $this->resolveAndStore($releaseId, $this->albumCandidate(), $this->albumEvidence());

        $document = $this->document($releaseId);
        $this->assertStringContainsString('Example Album', $document['album_title']);
        $this->assertStringContainsString('Alias Album', $document['album_title']);
        $this->assertStringNotContainsString('Legacy Album', $document['album_title']);
        $this->assertSame('Example Artist', $document['artist']);
        $this->assertStringContainsString('First Light', $document['music_tracks']);
        $this->assertStringContainsString('Last Light', $document['music_tracks']);
    }

    #[Test]
    public function an_unaccepted_candidate_adds_nothing_and_the_legacy_text_stays(): void
    {
        $releaseId = $this->release('Reviewed.Release-GRP', musicInfoId: 7);
        $evidence = $this->evidenceRecord($releaseId, 1);
        $this->persist($evidence, IdentificationStatus::NeedsReview);
        $this->persist($this->evidenceRecord($this->release('Conflicted.Release-GRP'), 1), IdentificationStatus::Conflicted);

        $document = $this->document($releaseId);
        $this->assertSame('Legacy Album', $document['album_title']);
        $this->assertSame('Legacy Artist', $document['artist']);
        $this->assertSame('', $document['music_tracks']);
    }

    #[Test]
    public function a_release_without_any_decision_keeps_its_legacy_text(): void
    {
        $document = $this->document($this->release('Plain.Release-GRP', musicInfoId: 7));

        $this->assertSame('Legacy Album', $document['album_title']);
        $this->assertSame('Legacy Artist', $document['artist']);
        $this->assertSame('', $document['music_tracks']);
    }

    #[Test]
    public function a_replacing_decision_updates_the_document_and_a_withdrawal_restores_the_legacy_text(): void
    {
        $releaseId = $this->release('Replaced.Release-GRP', musicInfoId: 7);
        $evidence = $this->resolveAndStore($releaseId, $this->albumCandidate(), $this->albumEvidence());
        $this->assertStringContainsString('Example Album', $this->document($releaseId)['album_title']);

        config(['music-identity.algorithm_version' => 'music-identity-v2']);
        $lease = (new MusicIdentityLeaseManager)->acquire($evidence, 'worker-a');
        $this->assertNotNull($lease);
        $this->persist($evidence, IdentificationStatus::RetryableError, leaseToken: 'worker-a');
        $this->assertStringContainsString('Example Album', $this->document($releaseId)['album_title'], 'an unfinished attempt keeps the accepted text');

        Carbon::setTestNow(now()->addHour());
        $this->assertNotNull((new MusicIdentityLeaseManager)->acquire($evidence, 'worker-b'));
        $this->persist($evidence, IdentificationStatus::Unresolved, leaseToken: 'worker-b');
        $withdrawn = $this->document($releaseId);
        $this->assertSame('Legacy Album', $withdrawn['album_title']);
        $this->assertSame('Legacy Artist', $withdrawn['artist']);
        $this->assertSame('', $withdrawn['music_tracks']);

        $this->resolveAndStore($releaseId, $this->recordingCandidate(), $this->recordingEvidence(), revision: 2);
        $replaced = $this->document($releaseId);
        $this->assertSame('Legacy Album', $replaced['album_title']);
        $this->assertStringContainsString('Recorded Track', $replaced['music_tracks']);
        Search::shouldHaveReceived('updateRelease')->with($releaseId)->times(4);
    }

    private function release(string $name, ?int $musicInfoId = null): int
    {
        $values = Release::factory()->raw([
            'name' => $name, 'searchname' => $name, 'guid' => md5($name), 'categories_id' => 3040, 'groups_id' => 0,
            'fromname' => 'poster@example.test', 'musicinfo_id' => $musicInfoId,
        ]);

        return DB::table('releases')->insertGetId(array_intersect_key($values, array_flip(DB::getSchemaBuilder()->getColumnListing('releases'))));
    }

    /** @return array<string, mixed> */
    private function document(int $releaseId): array
    {
        $document = ReleaseIndexProjection::forId($releaseId);
        $this->assertNotNull($document);

        return $document;
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

    private function persist(ReleaseAudioEvidence $evidence, IdentificationStatus $status, ?string $leaseToken = null): void
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
