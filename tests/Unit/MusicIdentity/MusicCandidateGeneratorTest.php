<?php

declare(strict_types=1);

namespace Tests\Unit\MusicIdentity;

use App\Services\MusicIdentity\Contracts\MusicBrainzGateway;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateIdentifiers;
use App\Services\MusicIdentity\DTO\CandidateMetadata;
use App\Services\MusicIdentity\DTO\RecordingCandidates;
use App\Services\MusicIdentity\DTO\RecordingQuery;
use App\Services\MusicIdentity\DTO\ReleaseCandidates;
use App\Services\MusicIdentity\DTO\ReleaseQuery;
use App\Services\MusicIdentity\DTO\TrackEvidence;
use App\Services\MusicIdentity\Matching\CandidateTextNormalizer;
use App\Services\MusicIdentity\Matching\DistinctiveTrackEvidenceSelector;
use App\Services\MusicIdentity\MusicCandidateGenerator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MusicCandidateGeneratorTest extends TestCase
{
    #[Test]
    public function embedded_artist_mbid_and_disc_toc_are_queried_as_exact_identifiers(): void
    {
        config([
            'music-identity.candidate_generation.distinctive_track_evidence_limit' => 0,
            'music-identity.candidate_generation.exact_identifier_limit' => 8,
        ]);
        $gateway = new EmptyCandidateGatewayFake;
        $evidence = new AudioEvidenceSet(
            evidenceId: 98,
            evidenceHash: str_repeat('8', 64),
            releaseTitle: 'Exact evidence',
            albumTitle: null,
            albumArtist: null,
            releaseYear: null,
            trackEvidence: [new TrackEvidence(
                evidenceTrackId: 18,
                sourceKind: 'cue',
                sourceOrdinal: 1,
                rawFilename: 'disc.cue',
                artistId: '66666666-6666-4666-8666-666666666666',
                discToc: '1 12 183150 150 15750 31200',
            )],
        );

        (new MusicCandidateGenerator($gateway))->generate($evidence);

        $normalizedQueries = array_map(
            static fn (RecordingQuery $query): array => $query->normalized(),
            $gateway->recordingQueries,
        );
        $this->assertNotContains('66666666-6666-4666-8666-666666666666', array_column($normalizedQueries, 'artistId'));
        $this->assertContains('1 12 183150 150 15750 31200', array_column($normalizedQueries, 'discToc'));
        $this->assertContains('66666666-6666-4666-8666-666666666666', array_map(
            static fn (CandidateIdentifiers $identifiers): ?string => $identifiers->normalized()['artistId'],
            $gateway->hydrationRequests,
        ));
    }

    #[Test]
    public function an_unresolved_embedded_release_mbid_is_not_presented_as_exact(): void
    {
        config([
            'music-identity.candidate_generation.distinctive_track_evidence_limit' => 0,
            'music-identity.candidate_generation.exact_identifier_limit' => 8,
        ]);
        $gateway = new EmptyCandidateGatewayFake;
        $evidence = new AudioEvidenceSet(
            evidenceId: 97,
            evidenceHash: str_repeat('7', 64),
            releaseTitle: 'Invalid identifier',
            albumTitle: null,
            albumArtist: null,
            releaseYear: null,
            trackEvidence: [new TrackEvidence(
                evidenceTrackId: 17,
                sourceKind: 'tag',
                sourceOrdinal: 1,
                rawFilename: '01.flac',
                releaseId: '77777777-7777-4777-8777-777777777777',
            )],
        );

        $pool = (new MusicCandidateGenerator($gateway))->generate($evidence);

        $this->assertSame([], $pool->candidates);
    }

    #[Test]
    public function exact_identifier_redirects_store_the_canonical_mbid(): void
    {
        config([
            'music-identity.candidate_generation.distinctive_track_evidence_limit' => 0,
            'music-identity.candidate_generation.exact_identifier_limit' => 1,
        ]);
        $gateway = new CanonicalIdentifierGatewayFake;
        $evidence = new AudioEvidenceSet(
            evidenceId: 99,
            evidenceHash: str_repeat('9', 64),
            releaseTitle: 'Redirected identifier',
            albumTitle: null,
            albumArtist: null,
            releaseYear: null,
            trackEvidence: [new TrackEvidence(
                evidenceTrackId: 19,
                sourceKind: 'tag',
                sourceOrdinal: 1,
                rawFilename: '01.flac',
                releaseId: '77777777-7777-4777-8777-777777777777',
            )],
        );

        $candidate = (new MusicCandidateGenerator($gateway))->generate($evidence)->candidates[0];

        $this->assertSame(CanonicalIdentifierGatewayFake::RELEASE_ID, $candidate->identity->releaseId);
        $this->assertSame(CanonicalIdentifierGatewayFake::RELEASE_ID, $candidate->signals[0]->value);
    }

    #[Test]
    public function conflicting_embedded_release_and_group_mbids_remain_separate_hypotheses(): void
    {
        config([
            'music-identity.candidate_generation.distinctive_track_evidence_limit' => 0,
            'music-identity.candidate_generation.exact_identifier_limit' => 2,
            'music-identity.candidate_generation.hydration_limit' => 0,
        ]);
        $evidence = new AudioEvidenceSet(
            evidenceId: 100,
            evidenceHash: str_repeat('0', 64),
            releaseTitle: 'Conflicting identifiers',
            albumTitle: null,
            albumArtist: null,
            releaseYear: null,
            trackEvidence: [new TrackEvidence(
                evidenceTrackId: 25,
                sourceKind: 'tag',
                sourceOrdinal: 1,
                rawFilename: '01.flac',
                releaseId: ConflictingIdentifierGatewayFake::RELEASE_ID,
                releaseGroupId: ConflictingIdentifierGatewayFake::SUPPLIED_GROUP_ID,
            )],
        );

        $pool = (new MusicCandidateGenerator(new ConflictingIdentifierGatewayFake))->generate($evidence);

        $this->assertCount(2, $pool->candidates);
        $this->assertSame(ConflictingIdentifierGatewayFake::RELEASE_GROUP_ID, $pool->candidates[0]->identity->releaseGroupId);
        $this->assertSame(ConflictingIdentifierGatewayFake::SUPPLIED_GROUP_ID, $pool->candidates[1]->identity->releaseGroupId);
        $this->assertSame('embedded_release_id', $pool->candidates[0]->signals[0]->kind->value);
        $this->assertSame('embedded_release_group_id', $pool->candidates[1]->signals[0]->kind->value);
    }

    #[Test]
    public function recordings_from_different_editions_converge_on_the_release_group(): void
    {
        config([
            'music-identity.candidate_generation.distinctive_track_evidence_limit' => 2,
            'music-identity.candidate_generation.exact_identifier_limit' => 0,
            'music-identity.candidate_generation.hydration_limit' => 0,
        ]);
        $gateway = new ReleaseGroupConvergenceGatewayFake;
        $evidence = new AudioEvidenceSet(
            evidenceId: 96,
            evidenceHash: str_repeat('6', 64),
            releaseTitle: 'Two editions',
            albumTitle: null,
            albumArtist: null,
            releaseYear: null,
            trackEvidence: [
                new TrackEvidence(15, 'nzb', 1, 'first.flac', 'First Distinctive Song'),
                new TrackEvidence(16, 'nzb', 2, 'second.flac', 'Second Distinctive Song'),
            ],
        );

        $pool = (new MusicCandidateGenerator($gateway))->generate($evidence);
        $groupCandidate = array_values(array_filter(
            $pool->candidates,
            static fn ($candidate): bool => $candidate->identity->releaseId === null
                && $candidate->identity->releaseGroupId === ReleaseGroupConvergenceGatewayFake::RELEASE_GROUP_ID,
        ))[0];

        $this->assertSame(2, $groupCandidate->distinctRecordingSupport());
    }

    #[Test]
    public function correlated_signal_count_does_not_outrank_independent_evidence(): void
    {
        config([
            'music-identity.candidate_generation.distinctive_track_evidence_limit' => 5,
            'music-identity.candidate_generation.exact_identifier_limit' => 0,
            'music-identity.candidate_generation.hydration_limit' => 0,
        ]);
        $gateway = new EvidenceRankingGatewayFake;
        $evidence = new AudioEvidenceSet(
            evidenceId: 95,
            evidenceHash: str_repeat('5', 64),
            releaseTitle: 'Evidence ranking',
            albumTitle: null,
            albumArtist: null,
            releaseYear: null,
            trackEvidence: [
                new TrackEvidence(20, 'tag', 1, 'a-one.flac', 'Correlated Alpha', provenanceFamily: 'same-file'),
                new TrackEvidence(21, 'tag', 2, 'a-two.flac', 'Correlated Beta', provenanceFamily: 'same-file'),
                new TrackEvidence(22, 'tag', 3, 'a-three.flac', 'Correlated Gamma', provenanceFamily: 'same-file'),
                new TrackEvidence(23, 'cue', 1, 'b-one.flac', 'Independent Alpha', provenanceFamily: 'cue-sheet'),
                new TrackEvidence(24, 'nzb', 1, 'b-two.flac', 'Independent Beta', provenanceFamily: 'nzb-subject'),
            ],
        );

        $pool = (new MusicCandidateGenerator($gateway))->generate($evidence);

        $this->assertSame(EvidenceRankingGatewayFake::INDEPENDENT_GROUP_ID, $pool->candidates[0]->identity->releaseGroupId);
        $this->assertSame(2, $pool->candidates[0]->independentEvidenceSupport());
    }

    #[Test]
    public function distinctive_selection_prefers_rare_artist_qualified_evidence(): void
    {
        $selector = new DistinctiveTrackEvidenceSelector(new CandidateTextNormalizer);
        $selected = $selector->select([
            new TrackEvidence(30, 'nzb', 1, 'common-1.flac', 'Long Common Repeated Title'),
            new TrackEvidence(31, 'nzb', 2, 'common-2.flac', 'Long Common Repeated Title'),
            new TrackEvidence(32, 'tag', 1, 'rare.flac', 'Xylophone', 'Specific Artist'),
        ], 1);

        $this->assertSame(32, $selected[0]->evidenceTrackId);
    }

    #[Test]
    public function ambiguous_exact_identifier_candidates_are_retained_while_hydration_is_bounded(): void
    {
        config([
            'music-identity.candidate_generation.distinctive_track_evidence_limit' => 0,
            'music-identity.candidate_generation.exact_identifier_limit' => 1,
            'music-identity.candidate_generation.hydration_limit' => 2,
        ]);
        $gateway = new AmbiguousCandidateGatewayFake;
        $generator = new MusicCandidateGenerator($gateway);
        $evidence = new AudioEvidenceSet(
            evidenceId: 94,
            evidenceHash: str_repeat('d', 64),
            releaseTitle: 'Ambiguous Disc',
            albumTitle: null,
            albumArtist: null,
            releaseYear: null,
            trackEvidence: [new TrackEvidence(
                evidenceTrackId: 13,
                sourceKind: 'cue',
                sourceOrdinal: 1,
                rawFilename: 'album.cue',
                discId: 'I5l9cCSFccLKFEKS.7wqSZAorPU-',
            )],
            trackEvidenceListComplete: true,
        );

        $pool = $generator->generate($evidence);

        $this->assertCount(6, $pool->candidates);
        $this->assertCount(2, $gateway->hydrationRequests);
        $this->assertCount(2, array_filter(
            $pool->candidates,
            static fn ($candidate): bool => $candidate->metadata->releases !== [],
        ));
    }

    #[Test]
    public function release_identifiers_and_album_hints_join_track_evidence_candidates_before_hydration(): void
    {
        config([
            'music-identity.candidate_generation.distinctive_track_evidence_limit' => 1,
            'music-identity.candidate_generation.exact_identifier_limit' => 8,
            'music-identity.candidate_generation.hydration_limit' => 3,
        ]);
        $gateway = new CandidateGeneratorGatewayFake;
        $generator = new MusicCandidateGenerator($gateway);
        $evidence = new AudioEvidenceSet(
            evidenceId: 93,
            evidenceHash: str_repeat('c', 64),
            releaseTitle: 'Radiohead.OK.Computer.1997.FLAC',
            albumTitle: 'OK_Computer',
            albumArtist: 'Radiohead',
            releaseYear: 1997,
            trackEvidence: [new TrackEvidence(
                evidenceTrackId: 12,
                sourceKind: 'sampled',
                sourceOrdinal: 1,
                rawFilename: '06 - Karma_Police.flac',
                title: 'Karma_Police',
                artist: 'Radiohead',
                durationMs: 264_000,
                musicBrainzReleaseTrackId: '55555555-5555-4555-8555-555555555555',
                artistId: '66666666-6666-4666-8666-666666666666',
                barcode: '724385522925',
                catalogNumber: '7243 8 55229 2 5',
                label: 'Parlophone',
            )],
            trackEvidenceListComplete: true,
        );

        $candidate = $generator->generate($evidence)->candidates[0];

        $signalKinds = array_values(array_unique(array_map(static fn ($signal): string => $signal->kind->value, $candidate->signals)));
        sort($signalKinds);
        $this->assertSame(
            ['barcode', 'catalog_number', 'embedded_release_track_id', 'release_search', 'track_evidence_search'],
            $signalKinds,
        );

        $this->assertTrue((bool) array_filter(
            $gateway->recordingQueries,
            static fn (RecordingQuery $query): bool => $query->normalized()['musicBrainzReleaseTrackId'] === '55555555-5555-4555-8555-555555555555',
        ));
        $trackEvidenceSearch = array_values(array_filter(
            $gateway->recordingQueries,
            static fn (RecordingQuery $query): bool => $query->normalized()['title'] === 'karma police',
        ))[0];
        $this->assertSame('66666666-6666-4666-8666-666666666666', $trackEvidenceSearch->normalized()['artistId']);
        $this->assertTrue($trackEvidenceSearch->normalized()['fuzzy']);

        $this->assertTrue((bool) array_filter(
            $gateway->releaseQueries,
            static fn (ReleaseQuery $query): bool => $query->normalized()['barcode'] === '724385522925',
        ));
        $this->assertTrue((bool) array_filter(
            $gateway->releaseQueries,
            static fn (ReleaseQuery $query): bool => $query->normalized()['catalogNumber'] === '7243 8 55229 2 5'
                && $query->normalized()['label'] === 'Parlophone',
        ));
        $albumSearch = array_values(array_filter(
            $gateway->releaseQueries,
            static fn (ReleaseQuery $query): bool => $query->normalized()['title'] === 'ok computer',
        ))[0];
        $this->assertSame(1997, $albumSearch->normalized()['year']);
        $this->assertSame('66666666-6666-4666-8666-666666666666', $albumSearch->normalized()['artistId']);
        $this->assertTrue($albumSearch->normalized()['fuzzy']);
        $this->assertSame(1, $candidate->independentEvidenceSupport());
        $this->assertCount(3, $gateway->hydrationRequests);
    }

    #[Test]
    public function exact_identifiers_are_validated_and_correlated_signals_share_one_provenance_family(): void
    {
        config([
            'music-identity.candidate_generation.distinctive_track_evidence_limit' => 0,
            'music-identity.candidate_generation.exact_identifier_limit' => 8,
            'music-identity.candidate_generation.hydration_limit' => 3,
        ]);
        $gateway = new CandidateGeneratorGatewayFake;
        $generator = new MusicCandidateGenerator($gateway);
        $evidence = new AudioEvidenceSet(
            evidenceId: 92,
            evidenceHash: str_repeat('b', 64),
            releaseTitle: 'Radiohead - OK Computer',
            albumTitle: 'OK Computer',
            albumArtist: 'Radiohead',
            releaseYear: 1997,
            trackEvidence: [
                new TrackEvidence(
                    evidenceTrackId: 10,
                    sourceKind: 'sampled',
                    sourceOrdinal: 1,
                    rawFilename: '06 - Karma Police.flac',
                    title: 'Karma Police',
                    artist: 'Radiohead',
                    recordingId: '22222222-2222-4222-8222-222222222222',
                    releaseId: CandidateGeneratorGatewayFake::RELEASE_ID,
                    releaseGroupId: CandidateGeneratorGatewayFake::RELEASE_GROUP_ID,
                    isrc: 'GBAYE9701373',
                    discId: 'I5l9cCSFccLKFEKS.7wqSZAorPU-',
                    provenanceFamily: 'sampled-track:10',
                ),
                new TrackEvidence(
                    evidenceTrackId: 11,
                    sourceKind: 'tag',
                    sourceOrdinal: 1,
                    rawFilename: 'derived-from-the-same-file.flac',
                    recordingId: 'not-a-valid-mbid',
                    provenanceFamily: 'sampled-track:10',
                ),
            ],
            trackEvidenceListComplete: false,
            albumProvenanceFamily: 'sampled-track:10',
        );

        $pool = $generator->generate($evidence);

        $candidate = $pool->candidates[0];
        $this->assertSame(1, $candidate->distinctRecordingSupport());
        $this->assertSame(1, $candidate->independentEvidenceSupport());
        $signalKinds = array_values(array_unique(array_map(static fn ($signal): string => $signal->kind->value, $candidate->signals)));
        sort($signalKinds);
        $this->assertSame(
            ['disc_id', 'embedded_recording_id', 'embedded_release_group_id', 'embedded_release_id', 'isrc', 'release_search'],
            $signalKinds,
        );

        $normalizedQueries = array_map(
            static fn (RecordingQuery $query): array => $query->normalized(),
            $gateway->recordingQueries,
        );
        $this->assertContains('22222222-2222-4222-8222-222222222222', array_column($normalizedQueries, 'recordingId'));
        $this->assertNotContains('not-a-valid-mbid', array_column($normalizedQueries, 'recordingId'));
        $this->assertContains('GBAYE9701373', array_column($normalizedQueries, 'isrc'));
        $this->assertContains('I5l9cCSFccLKFEKS.7wqSZAorPU-', array_column($normalizedQueries, 'discId'));
        $this->assertCount(3, $gateway->hydrationRequests);
    }

    #[Test]
    public function rare_track_evidence_converges_on_one_bounded_hydrated_candidate(): void
    {
        config([
            'music-identity.candidate_generation.distinctive_track_evidence_limit' => 2,
            'music-identity.candidate_generation.hydration_limit' => 3,
        ]);
        $gateway = new CandidateGeneratorGatewayFake;
        $generator = new MusicCandidateGenerator($gateway);
        $evidence = new AudioEvidenceSet(
            evidenceId: 91,
            evidenceHash: str_repeat('a', 64),
            releaseTitle: 'Armand Hammer - Mercy',
            albumTitle: 'Mercy',
            albumArtist: 'Armand Hammer',
            releaseYear: 2025,
            trackEvidence: [
                new TrackEvidence(1, 'nzb', 1, '01 - Intro.flac', 'Intro', 'Armand Hammer'),
                new TrackEvidence(2, 'nzb', 2, '02 - Calypso_Gene.flac', 'Calypso_Gene', 'Armand Hammer', 211_400),
                new TrackEvidence(3, 'nzb', 3, '03 - Peshawar.flac', 'Peshawar', 'Armand Hammer', 193_000),
                new TrackEvidence(4, 'nzb', 4, '04 - Glue Traps.flac', 'Glue Traps', 'Armand Hammer', 176_200),
            ],
            trackEvidenceListComplete: true,
        );

        $pool = $generator->generate($evidence);

        $this->assertCount(2, $pool->candidates);
        $candidate = $pool->candidates[0];
        $this->assertSame(CandidateGeneratorGatewayFake::RELEASE_ID, $candidate->identity->releaseId);
        $this->assertSame(CandidateGeneratorGatewayFake::RELEASE_GROUP_ID, $candidate->identity->releaseGroupId);
        $this->assertSame(2, $candidate->distinctRecordingSupport());
        $this->assertSame(1, $candidate->independentEvidenceSupport());
        $this->assertSame('Mercy', $candidate->metadata->releases[0]['title']);

        $this->assertSame(['calypso gene', 'glue traps'], array_map(
            static fn (RecordingQuery $query): ?string => $query->normalized()['title'],
            $gateway->recordingQueries,
        ));
        $this->assertSame([211_400, 176_200], array_map(
            static fn (RecordingQuery $query): ?int => $query->normalized()['durationMs'],
            $gateway->recordingQueries,
        ));
        $this->assertCount(2, $gateway->hydrationRequests);
    }
}

final class AmbiguousCandidateGatewayFake implements MusicBrainzGateway
{
    /** @var list<CandidateIdentifiers> */
    public array $hydrationRequests = [];

    public function candidatesFor(RecordingQuery $query): RecordingCandidates
    {
        unset($query);
        $recordings = [];
        foreach ([1, 2, 3] as $suffix) {
            $digit = (string) $suffix;
            $recordings[] = [
                'recordingId' => str_repeat($digit, 8).'-'.str_repeat($digit, 4).'-4'.str_repeat($digit, 3).'-8'.str_repeat($digit, 3).'-'.str_repeat($digit, 12),
                'title' => 'Ambiguous track '.$digit,
                'artistCredit' => null,
                'lengthMs' => null,
                'video' => false,
                'isrcs' => [],
                'releaseIds' => ['00000000-0000-4000-8000-00000000000'.$digit],
                'releaseGroupIds' => ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa'.$digit],
                'providerScore' => 100,
                'sources' => ['disc_id_lookup'],
            ];
        }

        return new RecordingCandidates($recordings, 3);
    }

    public function releaseCandidatesFor(ReleaseQuery $query): ReleaseCandidates
    {
        unset($query);

        return ReleaseCandidates::empty();
    }

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata
    {
        $this->hydrationRequests[] = $identifiers;
        $ids = $identifiers->normalized();

        return new CandidateMetadata([], [[
            'releaseId' => (string) $ids['releaseId'],
            'title' => 'Hydrated candidate',
            'artistCredit' => null,
            'releaseGroupId' => $ids['releaseGroupId'],
            'status' => null,
            'date' => null,
            'country' => null,
            'barcode' => null,
            'labels' => [],
            'media' => [],
        ]], []);
    }
}

final class CandidateGeneratorGatewayFake implements MusicBrainzGateway
{
    public const string RELEASE_ID = '11111111-1111-4111-8111-111111111111';

    public const string RELEASE_GROUP_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    /** @var list<RecordingQuery> */
    public array $recordingQueries = [];

    /** @var list<CandidateIdentifiers> */
    public array $hydrationRequests = [];

    /** @var list<ReleaseQuery> */
    public array $releaseQueries = [];

    public function candidatesFor(RecordingQuery $query): RecordingCandidates
    {
        $this->recordingQueries[] = $query;
        $title = $query->normalized()['title'];
        $recordingId = match ($title) {
            'calypso gene' => '22222222-2222-4222-8222-222222222222',
            'glue traps' => '33333333-3333-4333-8333-333333333333',
            default => '44444444-4444-4444-8444-444444444444',
        };

        return new RecordingCandidates([[
            'recordingId' => $recordingId,
            'title' => (string) $title,
            'artistCredit' => 'Armand Hammer',
            'lengthMs' => $query->normalized()['durationMs'],
            'video' => false,
            'isrcs' => [],
            'releaseIds' => [self::RELEASE_ID],
            'releaseGroupIds' => [self::RELEASE_GROUP_ID],
            'providerScore' => 100,
            'sources' => ['recording_search'],
        ]], 1);
    }

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata
    {
        $this->hydrationRequests[] = $identifiers;
        $ids = $identifiers->normalized();
        if ($ids['artistId'] !== null) {
            return new CandidateMetadata([], [], [], [[
                'artistId' => $ids['artistId'],
                'name' => 'Radiohead',
                'sortName' => 'Radiohead',
                'disambiguation' => null,
                'type' => 'Group',
                'country' => 'GB',
            ]]);
        }

        return new CandidateMetadata(
            recordings: [],
            releases: [[
                'releaseId' => self::RELEASE_ID,
                'title' => 'Mercy',
                'artistCredit' => 'Armand Hammer',
                'releaseGroupId' => self::RELEASE_GROUP_ID,
                'status' => 'Official',
                'date' => '2025-11-07',
                'country' => 'US',
                'barcode' => null,
                'labels' => [],
                'media' => [],
            ]],
            releaseGroups: [[
                'releaseGroupId' => self::RELEASE_GROUP_ID,
                'title' => 'Mercy',
                'artistCredit' => 'Armand Hammer',
                'primaryType' => 'Album',
                'secondaryTypes' => [],
                'firstReleaseDate' => '2025-11-07',
            ]],
        );
    }

    public function releaseCandidatesFor(ReleaseQuery $query): ReleaseCandidates
    {
        $this->releaseQueries[] = $query;

        return new ReleaseCandidates([[
            'releaseId' => self::RELEASE_ID,
            'releaseGroupId' => self::RELEASE_GROUP_ID,
            'title' => 'OK Computer',
            'artistCredit' => 'Radiohead',
            'providerScore' => 100,
            'sources' => ['release_search'],
        ]], 1);
    }
}

final class EmptyCandidateGatewayFake implements MusicBrainzGateway
{
    /** @var list<RecordingQuery> */
    public array $recordingQueries = [];

    /** @var list<CandidateIdentifiers> */
    public array $hydrationRequests = [];

    public function candidatesFor(RecordingQuery $query): RecordingCandidates
    {
        $this->recordingQueries[] = $query;

        return RecordingCandidates::empty();
    }

    public function releaseCandidatesFor(ReleaseQuery $query): ReleaseCandidates
    {
        unset($query);

        return ReleaseCandidates::empty();
    }

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata
    {
        $this->hydrationRequests[] = $identifiers;

        return CandidateMetadata::empty();
    }
}

final class CanonicalIdentifierGatewayFake implements MusicBrainzGateway
{
    public const string RELEASE_ID = '88888888-8888-4888-8888-888888888888';

    public function candidatesFor(RecordingQuery $query): RecordingCandidates
    {
        unset($query);

        return RecordingCandidates::empty();
    }

    public function releaseCandidatesFor(ReleaseQuery $query): ReleaseCandidates
    {
        unset($query);

        return ReleaseCandidates::empty();
    }

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata
    {
        unset($identifiers);

        return new CandidateMetadata([], [[
            'releaseId' => self::RELEASE_ID,
            'title' => 'Canonical release',
            'artistCredit' => null,
            'releaseGroupId' => null,
            'status' => null,
            'date' => null,
            'country' => null,
            'barcode' => null,
            'labels' => [],
            'media' => [],
        ]], []);
    }
}

final class ConflictingIdentifierGatewayFake implements MusicBrainzGateway
{
    public const string RELEASE_ID = '77777777-7777-4777-8777-777777777777';

    public const string RELEASE_GROUP_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public const string SUPPLIED_GROUP_ID = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    public function candidatesFor(RecordingQuery $query): RecordingCandidates
    {
        unset($query);

        return RecordingCandidates::empty();
    }

    public function releaseCandidatesFor(ReleaseQuery $query): ReleaseCandidates
    {
        unset($query);

        return ReleaseCandidates::empty();
    }

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata
    {
        $ids = $identifiers->normalized();
        if ($ids['releaseId'] !== null) {
            return new CandidateMetadata([], [[
                'releaseId' => self::RELEASE_ID,
                'title' => 'Release edition',
                'artistCredit' => null,
                'releaseGroupId' => self::RELEASE_GROUP_ID,
                'status' => null,
                'date' => null,
                'country' => null,
                'barcode' => null,
                'labels' => [],
                'media' => [],
            ]], []);
        }

        return new CandidateMetadata([], [], [[
            'releaseGroupId' => self::SUPPLIED_GROUP_ID,
            'title' => 'Conflicting group',
            'artistCredit' => null,
            'primaryType' => 'Album',
            'secondaryTypes' => [],
            'firstReleaseDate' => null,
        ]]);
    }
}

final class ReleaseGroupConvergenceGatewayFake implements MusicBrainzGateway
{
    public const string RELEASE_GROUP_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function candidatesFor(RecordingQuery $query): RecordingCandidates
    {
        $first = $query->normalized()['title'] === 'first distinctive song';

        return new RecordingCandidates([[
            'recordingId' => $first
                ? '11111111-1111-4111-8111-111111111111'
                : '22222222-2222-4222-8222-222222222222',
            'title' => (string) $query->normalized()['title'],
            'artistCredit' => null,
            'lengthMs' => null,
            'video' => false,
            'isrcs' => [],
            'releaseIds' => [$first
                ? '33333333-3333-4333-8333-333333333333'
                : '44444444-4444-4444-8444-444444444444'],
            'releaseGroupIds' => [self::RELEASE_GROUP_ID],
            'providerScore' => 100,
            'sources' => ['recording_search'],
        ]], 1);
    }

    public function releaseCandidatesFor(ReleaseQuery $query): ReleaseCandidates
    {
        unset($query);

        return ReleaseCandidates::empty();
    }

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata
    {
        unset($identifiers);

        return CandidateMetadata::empty();
    }
}

final class EvidenceRankingGatewayFake implements MusicBrainzGateway
{
    public const string INDEPENDENT_GROUP_ID = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    public function candidatesFor(RecordingQuery $query): RecordingCandidates
    {
        $independent = str_starts_with((string) $query->normalized()['title'], 'independent');

        return new RecordingCandidates([[
            'recordingId' => $independent
                ? '22222222-2222-4222-8222-222222222222'
                : '11111111-1111-4111-8111-111111111111',
            'title' => (string) $query->normalized()['title'],
            'artistCredit' => null,
            'lengthMs' => null,
            'video' => false,
            'isrcs' => [],
            'releaseIds' => [],
            'releaseGroupIds' => [$independent
                ? self::INDEPENDENT_GROUP_ID
                : 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'],
            'providerScore' => 100,
            'sources' => ['recording_search'],
        ]], 1);
    }

    public function releaseCandidatesFor(ReleaseQuery $query): ReleaseCandidates
    {
        unset($query);

        return ReleaseCandidates::empty();
    }

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata
    {
        unset($identifiers);

        return CandidateMetadata::empty();
    }
}
