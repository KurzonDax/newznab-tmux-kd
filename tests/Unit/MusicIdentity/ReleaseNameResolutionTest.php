<?php

declare(strict_types=1);

namespace Tests\Unit\MusicIdentity;

use App\Services\MusicIdentity\AcousticFingerprintCandidates;
use App\Services\MusicIdentity\Contracts\AcousticFingerprintMatcher;
use App\Services\MusicIdentity\Contracts\CandidateGenerator;
use App\Services\MusicIdentity\Contracts\MusicBrainzGateway;
use App\Services\MusicIdentity\DTO\AcousticFingerprintQuery;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateHypothesis;
use App\Services\MusicIdentity\DTO\CandidateIdentifiers;
use App\Services\MusicIdentity\DTO\CandidateIdentity;
use App\Services\MusicIdentity\DTO\CandidateMetadata;
use App\Services\MusicIdentity\DTO\CandidatePool;
use App\Services\MusicIdentity\DTO\CandidateSignal;
use App\Services\MusicIdentity\DTO\RecordingCandidates;
use App\Services\MusicIdentity\DTO\RecordingQuery;
use App\Services\MusicIdentity\DTO\ReleaseCandidates;
use App\Services\MusicIdentity\DTO\ReleaseGroupCandidates;
use App\Services\MusicIdentity\DTO\ReleaseGroupQuery;
use App\Services\MusicIdentity\DTO\ReleaseQuery;
use App\Services\MusicIdentity\DTO\TrackEvidence;
use App\Services\MusicIdentity\Enums\CandidateSignalKind;
use App\Services\MusicIdentity\Enums\IdentificationBand;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\Exceptions\MusicBrainzGatewayException;
use App\Services\MusicIdentity\Matching\ReleaseNameAlbumParser;
use App\Services\MusicIdentity\Matching\WholeReleaseAlignmentScorer;
use App\Services\MusicIdentity\MusicIdentityResolver;
use App\Services\MusicIdentity\ReleaseNameAlbumCandidates;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The resolver's release-name stage (issue #1033): a release the file evidence left without an
 * album takes the one release group its name identifies, unless the file evidence points to
 * another group or contradicts this one.
 */
final class ReleaseNameResolutionTest extends TestCase
{
    private const string NAME = 'Example Artist - Example Album (2020) [FLAC]';

    private const string GROUP = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const string OTHER_GROUP = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private const string RELEASE = '11111111-1111-4111-8111-111111111111';

    private const string OTHER_RELEASE = '44444444-4444-4444-8444-444444444444';

    private const string RECORDING = NameStageGateway::FIRST_RECORDING;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function a_release_without_candidates_takes_the_release_group_its_name_identifies(): void
    {
        $gateway = new NameStageGateway;

        $decision = $this->resolver([], $gateway)->resolve($this->evidence([]));

        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
        $this->assertSame(self::GROUP, $decision->acceptedIdentity?->releaseGroupId);
        $this->assertNull($decision->acceptedIdentity?->releaseId);
        $this->assertNull($decision->acceptedIdentity?->recordingId);
        $this->assertSame(['release_name_match'], array_map(static fn ($reason): string => $reason->code, $decision->reasons));
        $this->assertSame('The release name identifies one MusicBrainz release group (unique).', $decision->reasons[0]->description);
        $this->assertNull($decision->runnerUpMargin);
        $this->assertCount(1, $decision->candidates);
        $this->assertSame(self::GROUP, $decision->candidates[0]->identity->releaseGroupId);
        $this->assertSame($decision->candidates[0]->score, $decision->score);
        $this->assertSame(IdentificationBand::fromScore($decision->score), $decision->band);
        $this->assertLessThanOrEqual(5, $decision->score, 'no file agrees with the album, so the score stays the scorer\'s');
        $this->assertSame('Example Album', $decision->acceptedText?->title);
        $this->assertSame('Example Artist', $decision->acceptedText?->artistCredit);
        $this->assertContains('search:Example Artist|Example Album', $decision->candidates[0]->responseCacheKeys);
        $this->assertNull($decision->acoustIdLookedUpAt);
    }

    #[Test]
    public function without_the_name_stage_the_same_release_stays_unresolved(): void
    {
        $decision = (new MusicIdentityResolver(new FixedNameStageGenerator(new CandidatePool([]))))->resolve($this->evidence([]));

        $this->assertSame(IdentificationStatus::Unresolved, $decision->status);
        $this->assertSame('no_candidates', $decision->reasons[0]->code);
    }

    #[Test]
    public function an_accepted_recording_of_the_named_album_becomes_the_album_and_keeps_the_stronger_candidate_first(): void
    {
        $evidence = $this->evidence([new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000)]);
        $pool = [$this->recordingCandidate(self::RELEASE, self::GROUP)];
        $this->assertSame(IdentificationStatus::AcceptedRecording, $this->resolver($pool, null)->resolve($evidence)->status);

        $decision = $this->resolver($pool, new NameStageGateway)->resolve($evidence);

        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
        $this->assertEquals(new CandidateIdentity(releaseGroupId: self::GROUP), $decision->acceptedIdentity);
        $this->assertCount(2, $decision->candidates);
        $this->assertSame(self::RECORDING, $decision->candidates[0]->identity->recordingId, 'the file-evidence candidate of the group');
        $this->assertGreaterThan($decision->candidates[1]->score, $decision->candidates[0]->score);
        $this->assertSame($decision->candidates[0]->score, $decision->score);
        $this->assertSame(self::GROUP, $decision->candidates[1]->identity->releaseGroupId);
        $this->assertNull($decision->runnerUpMargin);
    }

    #[Test]
    public function file_evidence_scoring_75_or_more_for_another_group_keeps_its_decision(): void
    {
        $evidence = $this->evidence([new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000)]);
        $pool = [$this->recordingCandidate(self::OTHER_RELEASE, self::OTHER_GROUP)];
        $withoutNameStage = $this->resolver($pool, null)->resolve($evidence);
        $this->assertGreaterThanOrEqual(75, $withoutNameStage->candidates[0]->score);
        $gateway = new NameStageGateway;

        $decision = $this->resolver($pool, $gateway)->resolve($evidence);

        $this->assertEquals($withoutNameStage, $decision);
        $this->assertSame(['Example Artist|Example Album'], $gateway->searches, 'the name was matched; the file evidence outweighed it');
        $this->assertSame([], $gateway->hydrated);
    }

    #[Test]
    public function file_evidence_scoring_under_75_for_another_group_gives_way_to_the_name(): void
    {
        $evidence = $this->evidence([new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000)], complete: false);
        $weak = $this->albumCandidate(
            self::OTHER_RELEASE,
            self::OTHER_GROUP,
            ['Unrelated Song'],
            [new CandidateSignal(CandidateSignalKind::ReleaseSearch, 'example album', 'album-tags', false)],
            title: 'Another Record',
        );
        $withoutNameStage = $this->resolver([$weak], null)->resolve($evidence);
        $this->assertSame(IdentificationStatus::Unresolved, $withoutNameStage->status);
        $this->assertLessThan(75, $withoutNameStage->candidates[0]->score);

        $decision = $this->resolver([$weak], new NameStageGateway)->resolve($evidence);

        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
        $this->assertSame(self::GROUP, $decision->acceptedIdentity?->releaseGroupId);
        $this->assertSame(
            [self::GROUP, self::OTHER_GROUP],
            array_map(static fn ($summary): ?string => $summary->identity->releaseGroupId, $decision->candidates),
            'the accepted candidate is first whatever the other scores',
        );
        $this->assertSame($decision->candidates[0]->score - $decision->candidates[1]->score, $decision->runnerUpMargin);
    }

    #[Test]
    public function a_name_that_settles_two_close_groups_keeps_their_margin(): void
    {
        $evidence = $this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'Rare One', 'Example Artist', 180_000),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Rare Two', 'Example Artist', 210_000),
            new TrackEvidence(3, 'tag', 3, '03.flac', 'Rare Three', 'Example Artist', 210_000),
        ]);
        $pool = [
            $this->albumCandidate(self::RELEASE, self::GROUP, ['Rare One', 'Rare Two', 'Rare Three'], $this->searchedRecordings(self::GROUP)),
            $this->albumCandidate(self::OTHER_RELEASE, self::OTHER_GROUP, ['Rare One', 'Rare Two', 'Rare Three'], $this->searchedRecordings(self::OTHER_GROUP)),
        ];
        $withoutNameStage = $this->resolver($pool, null)->resolve($evidence);
        $this->assertSame(IdentificationStatus::NeedsReview, $withoutNameStage->status);
        $this->assertSame('runner_up_too_close', $withoutNameStage->reasons[0]->code);

        $decision = $this->resolver($pool, new NameStageGateway)->resolve($evidence);

        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
        $this->assertSame(self::GROUP, $decision->acceptedIdentity?->releaseGroupId);
        $this->assertSame(self::RELEASE, $decision->candidates[0]->identity->releaseId);
        $this->assertGreaterThanOrEqual(92, $decision->score);
        $this->assertSame(0, $decision->runnerUpMargin, 'the rename gate still sees how close the other group is');
    }

    #[Test]
    public function an_album_artist_tag_that_contradicts_the_named_group_keeps_the_decision(): void
    {
        $evidence = $this->evidence([], albumArtist: 'Entirely Different Artist');
        $withoutNameStage = $this->resolver([], null)->resolve($evidence);
        $gateway = new NameStageGateway;

        $decision = $this->resolver([], $gateway)->resolve($evidence);

        $this->assertEquals($withoutNameStage, $decision);
        $this->assertSame(IdentificationStatus::Unresolved, $decision->status);
        $this->assertCount(1, $gateway->hydrated, 'the named group was scored');
        $named = new CandidateHypothesis(new CandidateIdentity(releaseGroupId: self::GROUP), $gateway->hydrate($gateway->hydrated[0]), []);
        $this->assertSame(['strong_album_artist_conflict'], (new WholeReleaseAlignmentScorer)->score($evidence, $named)->contradictions);
    }

    #[Test]
    public function a_release_whose_files_already_accept_an_album_is_not_matched_by_name(): void
    {
        $signals = [];
        foreach (range(1, 3) as $index) {
            $signals[] = new CandidateSignal(
                CandidateSignalKind::EmbeddedRecordingId,
                'recording-'.$index,
                'tag-file:'.$index,
                true,
                new CandidateIdentity(recordingId: 'recording-'.$index, releaseGroupId: self::OTHER_GROUP),
            );
        }
        $album = $this->albumCandidate(self::OTHER_RELEASE, self::OTHER_GROUP, ['Rare One', 'Rare Two', 'Rare Three'], $signals);
        $gateway = new NameStageGateway;

        $decision = $this->resolver([$album], $gateway)->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'Rare One', 'Example Artist', 180_000),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Rare Two', 'Example Artist', 210_000),
            new TrackEvidence(3, 'tag', 3, '03.flac', 'Rare Three', 'Example Artist', 210_000),
        ]));

        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
        $this->assertSame(self::OTHER_GROUP, $decision->acceptedIdentity?->releaseGroupId);
        $this->assertSame('structural_gate_passed', $decision->reasons[0]->code);
        $this->assertSame([], $gateway->searches);
        $this->assertSame([], $gateway->hydrated);
    }

    #[Test]
    public function a_validated_embedded_recording_the_named_group_does_not_hold_keeps_the_decision(): void
    {
        $evidence = $this->evidence(
            [new TrackEvidence(1, 'tag', 1, '01.flac', 'Stray Song', 'Example Artist', 180_000, recordingId: self::RECORDING)],
            complete: false,
        );
        $recording = new CandidateHypothesis(
            new CandidateIdentity(recordingId: self::RECORDING),
            new CandidateMetadata([[
                'recordingId' => self::RECORDING, 'title' => 'Stray Song', 'artistCredit' => 'Example Artist', 'lengthMs' => 180_000,
                'video' => false, 'isrcs' => [], 'releaseIds' => [], 'releaseGroupIds' => [], 'providerScore' => null, 'sources' => ['fixture'],
            ]], [], []),
            [new CandidateSignal(CandidateSignalKind::EmbeddedRecordingId, self::RECORDING, 'tag-file:1', true, new CandidateIdentity(recordingId: self::RECORDING))],
        );
        $withoutNameStage = $this->resolver([$recording], null)->resolve($evidence);
        $this->assertNull($withoutNameStage->candidates[0]->identity->releaseGroupId, 'the file evidence names no other group');
        $gateway = new NameStageGateway;

        $decision = $this->resolver([$recording], $gateway)->resolve($evidence);

        $this->assertEquals($withoutNameStage, $decision);
        $this->assertCount(1, $gateway->hydrated, 'the named group was scored; it does not hold the embedded recording');
    }

    #[Test]
    public function a_failed_name_search_or_hydration_makes_the_attempt_retryable(): void
    {
        foreach ([new NameStageGateway(failsSearch: true), new NameStageGateway(failsHydration: true)] as $gateway) {
            $decision = $this->resolver([], $gateway)->resolve($this->evidence([]));

            $this->assertSame(IdentificationStatus::RetryableError, $decision->status);
            $this->assertSame('provider_retryable_error', $decision->reasons[0]->code);
            $this->assertSame('mirror unavailable', $decision->operationalError);
            $this->assertNull($decision->acceptedIdentity);
        }
    }

    #[Test]
    public function the_named_album_carries_its_release_groups_genres(): void
    {
        $gateway = new NameStageGateway(genres: [['name' => 'rock', 'count' => 5], ['name' => 'new wave', 'count' => 2]]);

        $decision = (new MusicIdentityResolver(
            candidateGenerator: new FixedNameStageGenerator(new CandidatePool([])),
            musicBrainz: $gateway,
            releaseNameAlbums: new ReleaseNameAlbumCandidates($gateway, new ReleaseNameAlbumParser),
        ))->resolve($this->evidence([]));

        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
        $this->assertSame([self::GROUP], $gateway->genreLookups);
        $this->assertSame([['name' => 'rock', 'count' => 5], ['name' => 'new wave', 'count' => 2]], $decision->releaseGroupGenres);
    }

    #[Test]
    public function a_name_match_after_a_fingerprint_lookup_keeps_the_lookup_time(): void
    {
        Carbon::setTestNow('2026-10-10 12:00:00');
        $gateway = new NameStageGateway;
        $fingerprinted = new TrackEvidence(
            1, 'archive', 1, 'a1b2c3.flac', null, null, 180_000,
            provenanceFamily: 'evidence:7:archive:1',
            fingerprint: 'fp-1',
            fingerprintHash: hash('sha256', 'fp-1'),
            fingerprintAlgorithm: 2,
            fingerprintGeneratorVersion: 'synthetic-generator-v1',
        );

        $decision = (new MusicIdentityResolver(
            candidateGenerator: new FixedNameStageGenerator(new CandidatePool([])),
            fingerprintCandidates: new AcousticFingerprintCandidates(new NoMatchFingerprintMatcher, $gateway),
            releaseNameAlbums: new ReleaseNameAlbumCandidates($gateway, new ReleaseNameAlbumParser),
        ))->resolve($this->evidence([$fingerprinted], complete: false));

        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
        $this->assertSame('2026-10-10 12:00:00', $decision->acoustIdLookedUpAt?->format('Y-m-d H:i:s'));
    }

    /** @param list<CandidateHypothesis> $pool */
    private function resolver(array $pool, ?NameStageGateway $gateway): MusicIdentityResolver
    {
        return new MusicIdentityResolver(
            candidateGenerator: new FixedNameStageGenerator(new CandidatePool($pool)),
            releaseNameAlbums: $gateway === null ? null : new ReleaseNameAlbumCandidates($gateway, new ReleaseNameAlbumParser),
        );
    }

    /** @param list<TrackEvidence> $trackEvidence */
    private function evidence(array $trackEvidence, ?bool $complete = true, ?string $albumArtist = null): AudioEvidenceSet
    {
        return new AudioEvidenceSet(
            evidenceId: 7,
            evidenceHash: str_repeat('e', 64),
            releaseTitle: self::NAME,
            albumTitle: $trackEvidence === [] ? null : 'Example Album',
            albumArtist: $albumArtist ?? ($trackEvidence === [] ? null : 'Example Artist'),
            releaseYear: $trackEvidence === [] ? null : 2020,
            trackEvidence: $trackEvidence,
            trackEvidenceListComplete: $trackEvidence === [] ? null : $complete,
            albumProvenanceFamily: 'album-tags',
        );
    }

    /**
     * Three searched recordings, each from its own file, on a release of the given group.
     *
     * @return list<CandidateSignal>
     */
    private function searchedRecordings(string $releaseGroupId): array
    {
        $signals = [];
        foreach (range(1, 3) as $index) {
            $signals[] = new CandidateSignal(
                CandidateSignalKind::TrackEvidenceSearch,
                'search-result-'.$index,
                'search-file:'.$index,
                false,
                new CandidateIdentity(recordingId: sprintf('77777777-7777-4777-8777-%012d', $index), releaseGroupId: $releaseGroupId),
            );
        }

        return $signals;
    }

    /** A one-track text match on a release of the given group: the files accept its recording, never the album. */
    private function recordingCandidate(string $releaseId, string $releaseGroupId): CandidateHypothesis
    {
        $identity = new CandidateIdentity(recordingId: self::RECORDING, releaseId: $releaseId, releaseGroupId: $releaseGroupId);

        return $this->albumCandidate(
            $releaseId,
            $releaseGroupId,
            ['First Light'],
            [new CandidateSignal(CandidateSignalKind::TrackEvidenceSearch, 'first light', 'tag-file:1', false, $identity)],
            identity: $identity,
        );
    }

    /**
     * @param  list<string>  $titles
     * @param  list<CandidateSignal>  $signals
     */
    private function albumCandidate(
        string $releaseId,
        string $releaseGroupId,
        array $titles,
        array $signals,
        string $title = 'Example Album',
        ?CandidateIdentity $identity = null,
    ): CandidateHypothesis {
        return new CandidateHypothesis(
            $identity ?? new CandidateIdentity(releaseId: $releaseId, releaseGroupId: $releaseGroupId),
            NameStageGateway::album($releaseId, $releaseGroupId, $title, $titles),
            $signals,
        );
    }
}

final readonly class FixedNameStageGenerator implements CandidateGenerator
{
    public function __construct(private CandidatePool $pool) {}

    public function generate(AudioEvidenceSet $evidence): CandidatePool
    {
        return $this->pool;
    }
}

final class NoMatchFingerprintMatcher implements AcousticFingerprintMatcher
{
    public function available(): bool
    {
        return true;
    }

    public function lookup(AcousticFingerprintQuery $query): array
    {
        return [];
    }
}

/**
 * MusicBrainz as the name stage sees it: the search for "Example Artist" / "Example Album" returns
 * one release group, which hydrates to one edition with three tracks.
 *
 * @phpstan-import-type MusicGenre from CandidateMetadata
 */
final class NameStageGateway implements MusicBrainzGateway
{
    private const string GROUP = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const string EDITION = '99999999-9999-4999-8999-999999999999';

    /** The recording of the first track of every album this double builds. */
    public const string FIRST_RECORDING = '55555555-5555-4555-8555-555555555555';

    /** @var list<string> every release-group search, as "artist|title" */
    public array $searches = [];

    /** @var list<CandidateIdentifiers> */
    public array $hydrated = [];

    /** @var list<string> */
    public array $genreLookups = [];

    /** @param list<MusicGenre> $genres */
    public function __construct(
        private readonly bool $failsSearch = false,
        private readonly bool $failsHydration = false,
        private readonly array $genres = [],
    ) {}

    public function candidatesFor(RecordingQuery $query): RecordingCandidates
    {
        return RecordingCandidates::empty();
    }

    public function releaseCandidatesFor(ReleaseQuery $query): ReleaseCandidates
    {
        return ReleaseCandidates::empty();
    }

    public function releaseGroupCandidatesFor(ReleaseGroupQuery $query): ReleaseGroupCandidates
    {
        if ($this->failsSearch) {
            throw new MusicBrainzGatewayException('mirror unavailable');
        }
        $key = $query->artist.'|'.$query->title;
        $this->searches[] = $key;
        if ($key !== 'Example Artist|Example Album') {
            return ReleaseGroupCandidates::empty();
        }

        return new ReleaseGroupCandidates(self::album(self::EDITION, self::GROUP, 'Example Album', [])->releaseGroups, 1, ['search:'.$key]);
    }

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata
    {
        if ($this->failsHydration) {
            throw new MusicBrainzGatewayException('mirror unavailable');
        }
        $this->hydrated[] = $identifiers;

        return self::album(self::EDITION, (string) $identifiers->releaseGroupId, 'Example Album', ['First Light', 'Middle Light', 'Last Light']);
    }

    public function releaseGroup(string $releaseGroupId): ?array
    {
        $this->genreLookups[] = $releaseGroupId;

        return [...self::album(self::EDITION, $releaseGroupId, 'Example Album', [])->releaseGroups[0], 'genres' => $this->genres];
    }

    /** @param list<string> $titles */
    public static function album(string $releaseId, string $releaseGroupId, string $title, array $titles): CandidateMetadata
    {
        $releaseTracks = [];
        foreach ($titles as $index => $trackTitle) {
            $length = $index === 0 ? 180_000 : 210_000;
            $releaseTracks[] = [
                'musicBrainzReleaseTrackId' => sprintf('33333333-3333-4333-8333-%012d', $index + 1),
                'title' => $trackTitle,
                'position' => $index + 1,
                'number' => (string) ($index + 1),
                'lengthMs' => $length,
                'artistCredit' => 'Example Artist',
                'recording' => [
                    'recordingId' => $index === 0 ? self::FIRST_RECORDING : sprintf('22222222-2222-4222-8222-%012d', $index + 1),
                    'title' => $trackTitle,
                    'artistCredit' => 'Example Artist',
                    'lengthMs' => $length,
                    'video' => false,
                    'isrcs' => [],
                    'releaseIds' => [$releaseId],
                    'releaseGroupIds' => [$releaseGroupId],
                    'providerScore' => null,
                    'sources' => ['fixture'],
                ],
            ];
        }

        return new CandidateMetadata([], $titles === [] ? [] : [[
            'releaseId' => $releaseId,
            'title' => $title,
            'artistCredit' => 'Example Artist',
            'releaseGroupId' => $releaseGroupId,
            'status' => 'Official',
            'date' => '2020-01-01',
            'country' => 'US',
            'barcode' => null,
            'labels' => [],
            'media' => [[
                'position' => 1,
                'title' => null,
                'format' => 'CD',
                'releaseTrackCount' => count($releaseTracks),
                'discIds' => [],
                'releaseTracks' => $releaseTracks,
            ]],
        ]], [[
            'releaseGroupId' => $releaseGroupId,
            'title' => $title,
            'artistCredit' => 'Example Artist',
            'primaryType' => 'Album',
            'secondaryTypes' => [],
            'firstReleaseDate' => '2020-01-01',
        ]]);
    }
}
