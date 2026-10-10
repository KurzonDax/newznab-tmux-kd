<?php

declare(strict_types=1);

namespace Tests\Unit\MusicIdentity;

use App\Services\MusicIdentity\AcousticFingerprintCandidates;
use App\Services\MusicIdentity\Contracts\AcousticFingerprintMatcher;
use App\Services\MusicIdentity\Contracts\CandidateGenerator;
use App\Services\MusicIdentity\Contracts\MusicBrainzGateway;
use App\Services\MusicIdentity\DTO\AcousticFingerprintMatch;
use App\Services\MusicIdentity\DTO\AcousticFingerprintQuery;
use App\Services\MusicIdentity\DTO\AcousticRecording;
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
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\Exceptions\AcousticFingerprintLookupException;
use App\Services\MusicIdentity\MusicIdentityResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Scoring-integration cohorts for fingerprint lookups (#312): the resolver consults AcoustID only
 * for an unresolved or ambiguous release, a fingerprint match supports at most a recording, and
 * album acceptance still needs the existing structural gates.
 */
final class AcousticFingerprintResolutionTest extends TestCase
{
    private const string RELEASE_ID = 'c0ffee00-0000-4000-8000-0000000000b1';

    private const string OTHER_RELEASE_ID = 'c0ffee00-0000-4000-8000-0000000000b9';

    private const string RELEASE_GROUP_ID = 'c0ffee00-0000-4000-8000-0000000000a1';

    private const string OTHER_RELEASE_GROUP_ID = 'c0ffee00-0000-4000-8000-0000000000a9';

    private const array TITLES = ['Amber Signal', 'Copper Tide', 'Glass Harbour'];

    private const array LENGTHS = [181_000, 207_000, 239_000];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        config(['music-identity.candidate_generation.hydration_limit' => 8]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function an_acoustic_match_with_no_musicbrainz_link_adds_no_candidate(): void
    {
        $matcher = new ScriptedFingerprintMatcher(['fp-1' => [new AcousticFingerprintMatch('0a1b2c3d-0000-4000-8000-000000000002', 0.91, [])]]);
        $gateway = new AlbumHydratingGateway([]);

        $decision = $this->resolver([], $matcher, $gateway)->resolve($this->evidence(fingerprinted: 1));

        $this->assertSame(IdentificationStatus::Unresolved, $decision->status);
        $this->assertSame([], $decision->candidates);
        $this->assertSame(['fp-1'], $matcher->lookedUp);
        $this->assertSame([], $gateway->hydrated);
        $this->assertSame('2026-10-08 12:00:00', $decision->acoustIdLookedUpAt?->format('Y-m-d H:i:s'), 'a no-match is still a lookup');
    }

    #[Test]
    public function one_fingerprint_match_accepts_at_most_a_recording_never_an_album(): void
    {
        $matcher = new ScriptedFingerprintMatcher(['fp-1' => [$this->match([$this->recordingOn(1, self::RELEASE_ID, self::RELEASE_GROUP_ID)])]]);

        $decision = $this->resolver([], $matcher, $this->albumGateway())->resolve($this->evidence(fingerprinted: 1, year: 2020));

        $this->assertSame(IdentificationStatus::AcceptedRecording, $decision->status);
        $this->assertSame($this->recordingId(1), $decision->acceptedIdentity?->recordingId);
        $this->assertNull($decision->acceptedIdentity?->releaseGroupId, 'a single fingerprint never selects an album');
        $this->assertSame(1, $decision->candidates[0]->featureVector['independent_recording_support']);
        $this->assertSame(96, $decision->candidates[0]->featureVector['fingerprint_provider_score']);
        $this->assertArrayNotHasKey('fingerprint_provider_score', $decision->candidates[0]->scoreContributions, 'the provider score never adds to the score');
    }

    #[Test]
    public function several_linked_recordings_for_one_fingerprint_stay_ambiguous(): void
    {
        $matcher = new ScriptedFingerprintMatcher(['fp-1' => [$this->match([
            $this->recordingOn(1, self::RELEASE_ID, self::RELEASE_GROUP_ID),
            new AcousticRecording('c0ffee00-0000-4000-8000-000000000099', [self::OTHER_RELEASE_GROUP_ID], [self::OTHER_RELEASE_ID => self::OTHER_RELEASE_GROUP_ID]),
        ])]]);
        $gateway = $this->albumGateway(alsoOtherAlbum: true);

        $decision = $this->resolver([], $matcher, $gateway)->resolve($this->evidence(fingerprinted: 1, year: 2020));

        $this->assertSame(IdentificationStatus::NeedsReview, $decision->status);
        $this->assertNull($decision->acceptedIdentity);
        $this->assertSame('runner_up_too_close', $decision->reasons[0]->code);
    }

    #[Test]
    public function independently_fingerprinted_recordings_converging_in_order_accept_the_release_group_through_the_gate(): void
    {
        $matcher = new ScriptedFingerprintMatcher([
            'fp-1' => [$this->match([$this->recordingOn(1, self::RELEASE_ID, self::RELEASE_GROUP_ID)])],
            'fp-2' => [$this->match([$this->recordingOn(2, self::RELEASE_ID, self::RELEASE_GROUP_ID)])],
            'fp-3' => [$this->match([$this->recordingOn(3, self::RELEASE_ID, self::RELEASE_GROUP_ID)])],
        ]);

        $decision = $this->resolver([], $matcher, $this->albumGateway())->resolve($this->evidence(fingerprinted: 3));

        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
        $this->assertSame(self::RELEASE_GROUP_ID, $decision->acceptedIdentity?->releaseGroupId);
        $this->assertSame(3, $decision->candidates[0]->featureVector['independent_recording_support']);
        $this->assertSame('structural_gate_passed', $decision->reasons[0]->code);
    }

    #[Test]
    public function converging_fingerprints_without_a_strong_ordered_alignment_cannot_bypass_the_gate(): void
    {
        $matcher = new ScriptedFingerprintMatcher([
            'fp-1' => [$this->match([$this->recordingOn(1, self::RELEASE_ID, self::RELEASE_GROUP_ID)])],
            'fp-2' => [$this->match([$this->recordingOn(2, self::RELEASE_ID, self::RELEASE_GROUP_ID)])],
            'fp-3' => [$this->match([$this->recordingOn(3, self::RELEASE_ID, self::RELEASE_GROUP_ID)])],
        ]);

        $decision = $this->resolver([], $matcher, $this->albumGateway())->resolve($this->evidence(fingerprinted: 3, obfuscated: true));

        $this->assertNotContains($decision->status, [IdentificationStatus::AcceptedReleaseGroup, IdentificationStatus::AcceptedEdition]);
        $this->assertSame(3, $decision->candidates[0]->featureVector['independent_recording_support']);
    }

    #[Test]
    public function a_duration_conflict_contradicts_the_fingerprint_match(): void
    {
        $matcher = new ScriptedFingerprintMatcher(['fp-1' => [$this->match([$this->recordingOn(1, self::RELEASE_ID, self::RELEASE_GROUP_ID)])]]);

        $decision = $this->resolver([], $matcher, $this->albumGateway(firstLengthMs: 412_000))
            ->resolve($this->evidence(fingerprinted: 1, year: 2020));

        $this->assertNull($decision->acceptedIdentity);
        $conflicted = array_values(array_filter(
            $decision->candidates,
            static fn ($candidate): bool => $candidate->identity->releaseId === self::RELEASE_ID,
        ));
        $this->assertCount(1, $conflicted);
        $this->assertContains('fingerprint_duration_conflict', $conflicted[0]->contradictions);
    }

    #[Test]
    public function one_agreeing_linked_recording_clears_a_fingerprint_of_a_duration_conflict(): void
    {
        // The second file (207 s) links its own recording (207 s) and the first track's (412 s here).
        $matcher = new ScriptedFingerprintMatcher(['fp-2' => [$this->match([
            $this->recordingOn(2, self::RELEASE_ID, self::RELEASE_GROUP_ID),
            $this->recordingOn(1, self::RELEASE_ID, self::RELEASE_GROUP_ID),
        ])]]);

        $decision = $this->resolver([], $matcher, $this->albumGateway(firstLengthMs: 412_000))
            ->resolve($this->evidence(fingerprinted: 2, year: 2020));

        $candidate = array_values(array_filter(
            $decision->candidates,
            static fn ($candidate): bool => $candidate->identity->releaseId === self::RELEASE_ID,
        ))[0];
        $this->assertNotContains('fingerprint_duration_conflict', $candidate->contradictions);
    }

    #[Test]
    public function an_accepted_first_pass_is_never_looked_up(): void
    {
        $matcher = new ScriptedFingerprintMatcher([]);
        $embedded = new CandidateHypothesis(
            new CandidateIdentity(releaseId: self::RELEASE_ID, releaseGroupId: self::RELEASE_GROUP_ID),
            $this->albumGateway()->album(self::RELEASE_ID, self::RELEASE_GROUP_ID, 'Synthetic Album', self::LENGTHS[0]),
            [new CandidateSignal(CandidateSignalKind::EmbeddedReleaseId, self::RELEASE_ID, 'tag-file:1', true, new CandidateIdentity(releaseId: self::RELEASE_ID, releaseGroupId: self::RELEASE_GROUP_ID))],
        );

        $decision = $this->resolver([$embedded], $matcher, $this->albumGateway())->resolve($this->evidence(fingerprinted: 1, year: 2020));

        $this->assertSame(IdentificationStatus::AcceptedEdition, $decision->status);
        $this->assertSame([], $matcher->lookedUp);
        $this->assertNull($decision->acoustIdLookedUpAt);
    }

    #[Test]
    public function without_a_configured_matcher_or_a_reliable_duration_nothing_is_looked_up(): void
    {
        $dormant = new ScriptedFingerprintMatcher([], available: false);
        $decision = $this->resolver([], $dormant, $this->albumGateway())->resolve($this->evidence(fingerprinted: 1));
        $this->assertSame([], $dormant->lookedUp);
        $this->assertNull($decision->acoustIdLookedUpAt);

        $configured = new ScriptedFingerprintMatcher([]);
        $decision = $this->resolver([], $configured, $this->albumGateway())->resolve($this->evidence(fingerprinted: 1, reliableDuration: false));
        $this->assertSame([], $configured->lookedUp);
        $this->assertNull($decision->acoustIdLookedUpAt);
    }

    #[Test]
    public function a_retryable_lookup_failure_defers_the_whole_decision(): void
    {
        $matcher = new ScriptedFingerprintMatcher(['fp-1' => new AcousticFingerprintLookupException('AcoustID lookup returned HTTP 503.', retryable: true)]);

        $decision = $this->resolver([], $matcher, $this->albumGateway())->resolve($this->evidence(fingerprinted: 1));

        $this->assertSame(IdentificationStatus::RetryableError, $decision->status);
        $this->assertSame('acoustid_retryable_error', $decision->reasons[0]->code);
        $this->assertNull($decision->acoustIdLookedUpAt);
    }

    #[Test]
    public function a_rejected_fingerprint_adds_nothing_and_the_decision_proceeds(): void
    {
        $matcher = new ScriptedFingerprintMatcher([
            'fp-1' => new AcousticFingerprintLookupException('AcoustID lookup returned HTTP 400 (error 3: invalid fingerprint).', retryable: false),
            'fp-2' => [$this->match([$this->recordingOn(2, self::RELEASE_ID, self::RELEASE_GROUP_ID)])],
        ]);

        Log::shouldReceive('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, 'AcoustID rejected') && $context['evidence_track_id'] === 1,
        );

        $decision = $this->resolver([], $matcher, $this->albumGateway())->resolve($this->evidence(fingerprinted: 2, year: 2020));

        $this->assertSame(['fp-1', 'fp-2'], $matcher->lookedUp);
        $this->assertSame(IdentificationStatus::AcceptedRecording, $decision->status);
        $this->assertSame($this->recordingId(2), $decision->acceptedIdentity?->recordingId);
        $this->assertNotNull($decision->acoustIdLookedUpAt);
    }

    #[Test]
    public function the_lookup_step_stops_retryably_once_its_time_budget_is_spent(): void
    {
        config(['music-identity.acoustid.lookup_budget_seconds' => 60]);
        $matcher = new ScriptedFingerprintMatcher([], onLookup: static function (): void {
            Carbon::setTestNow(now()->addSeconds(61));
        });

        $decision = $this->resolver([], $matcher, $this->albumGateway())->resolve($this->evidence(fingerprinted: 2));

        $this->assertSame(['fp-1'], $matcher->lookedUp, 'no lookup starts after the budget is spent');
        $this->assertSame(IdentificationStatus::RetryableError, $decision->status);
        $this->assertSame('acoustid_retryable_error', $decision->reasons[0]->code);
    }

    #[Test]
    public function the_duration_conflict_tolerances_come_from_configuration(): void
    {
        config([
            'music-identity.scoring.fingerprint_duration_tolerance_milliseconds' => 1_000,
            'music-identity.scoring.fingerprint_duration_tolerance_ratio' => 0.01,
        ]);
        $matcher = new ScriptedFingerprintMatcher(['fp-1' => [$this->match([$this->recordingOn(1, self::RELEASE_ID, self::RELEASE_GROUP_ID)])]]);
        $this->app->instance(CandidateGenerator::class, new FixedFingerprintTestGenerator(new CandidatePool([])));
        $this->app->instance(AcousticFingerprintCandidates::class, new AcousticFingerprintCandidates(
            $matcher,
            $this->albumGateway(firstLengthMs: self::LENGTHS[0] + 5_000),
        ));

        $decision = app(MusicIdentityResolver::class)->resolve($this->evidence(fingerprinted: 1, year: 2020));

        $conflicted = array_values(array_filter(
            $decision->candidates,
            static fn ($candidate): bool => $candidate->identity->releaseId === self::RELEASE_ID,
        ));
        $this->assertContains('fingerprint_duration_conflict', $conflicted[0]->contradictions, 'five seconds apart exceeds the configured tolerance');
    }

    /** @param list<CandidateHypothesis> $firstPass */
    private function resolver(array $firstPass, AcousticFingerprintMatcher $matcher, MusicBrainzGateway $gateway): MusicIdentityResolver
    {
        return new MusicIdentityResolver(
            candidateGenerator: new FixedFingerprintTestGenerator(new CandidatePool($firstPass)),
            fingerprintCandidates: new AcousticFingerprintCandidates($matcher, $gateway),
        );
    }

    private function evidence(int $fingerprinted, ?int $year = null, bool $obfuscated = false, bool $reliableDuration = true): AudioEvidenceSet
    {
        $tracks = [];
        foreach (self::TITLES as $index => $title) {
            $ordinal = $index + 1;
            $fingerprint = $ordinal <= $fingerprinted ? 'fp-'.$ordinal : null;
            $tracks[] = new TrackEvidence(
                evidenceTrackId: $ordinal,
                sourceKind: 'archive',
                sourceOrdinal: $ordinal,
                rawFilename: $obfuscated ? sprintf('%s.flac', md5($title)) : sprintf('%02d - %s.flac', $ordinal, $title),
                title: $obfuscated ? null : $title,
                artist: $obfuscated ? null : 'Example Artist',
                durationMs: $reliableDuration ? self::LENGTHS[$index] : null,
                provenanceFamily: 'evidence:7:archive:'.$ordinal,
                fingerprint: $fingerprint,
                fingerprintHash: $fingerprint === null ? null : hash('sha256', $fingerprint),
                fingerprintAlgorithm: $fingerprint === null ? null : 2,
                fingerprintGeneratorVersion: $fingerprint === null ? null : 'synthetic-generator-v1',
            );
        }

        return new AudioEvidenceSet(
            evidenceId: 7,
            evidenceHash: str_repeat('e', 64),
            releaseTitle: 'Example.Artist-Synthetic.Album-2020-GRP',
            albumTitle: $obfuscated ? null : 'Synthetic Album',
            albumArtist: $obfuscated ? null : 'Example Artist',
            releaseYear: $year,
            trackEvidence: $tracks,
            albumProvenanceFamily: 'evidence:7:album',
        );
    }

    /** @param list<AcousticRecording> $recordings */
    private function match(array $recordings): AcousticFingerprintMatch
    {
        return new AcousticFingerprintMatch('0a1b2c3d-0000-4000-8000-000000000001', 0.962, $recordings);
    }

    private function recordingOn(int $track, string $releaseId, string $releaseGroupId): AcousticRecording
    {
        return new AcousticRecording($this->recordingId($track), [$releaseGroupId], [$releaseId => $releaseGroupId]);
    }

    private function recordingId(int $track): string
    {
        return sprintf('c0ffee00-0000-4000-8000-%012d', $track);
    }

    private function albumGateway(bool $alsoOtherAlbum = false, int $firstLengthMs = self::LENGTHS[0]): AlbumHydratingGateway
    {
        $gateway = new AlbumHydratingGateway([]);
        $albums = [self::RELEASE_ID => $gateway->album(self::RELEASE_ID, self::RELEASE_GROUP_ID, 'Synthetic Album', $firstLengthMs)];
        if ($alsoOtherAlbum) {
            $albums[self::OTHER_RELEASE_ID] = $gateway->album(
                self::OTHER_RELEASE_ID,
                self::OTHER_RELEASE_GROUP_ID,
                'Synthetic Album',
                $firstLengthMs,
                firstRecordingId: 'c0ffee00-0000-4000-8000-000000000099',
            );
        }

        return new AlbumHydratingGateway($albums);
    }
}

final class ScriptedFingerprintMatcher implements AcousticFingerprintMatcher
{
    /** @var list<string> */
    public array $lookedUp = [];

    /** @param array<string, list<AcousticFingerprintMatch>|AcousticFingerprintLookupException> $answers */
    public function __construct(
        private readonly array $answers,
        private readonly bool $available = true,
        private readonly ?\Closure $onLookup = null,
    ) {}

    public function available(): bool
    {
        return $this->available;
    }

    public function lookup(AcousticFingerprintQuery $query): array
    {
        $this->lookedUp[] = $query->fingerprint;
        if ($this->onLookup !== null) {
            ($this->onLookup)();
        }
        $answer = $this->answers[$query->fingerprint] ?? [];
        if ($answer instanceof AcousticFingerprintLookupException) {
            throw $answer;
        }

        return $answer;
    }
}

final class AlbumHydratingGateway implements MusicBrainzGateway
{
    /** @var list<CandidateIdentifiers> */
    public array $hydrated = [];

    /** @param array<string, CandidateMetadata> $albums keyed by release ID */
    public function __construct(private readonly array $albums) {}

    public function candidatesFor(RecordingQuery $query): RecordingCandidates
    {
        return RecordingCandidates::empty();
    }

    public function releaseGroupCandidatesFor(ReleaseGroupQuery $query): ReleaseGroupCandidates
    {
        return ReleaseGroupCandidates::empty();
    }

    public function releaseCandidatesFor(ReleaseQuery $query): ReleaseCandidates
    {
        return new ReleaseCandidates([]);
    }

    public function releaseGroup(string $releaseGroupId): ?array
    {
        unset($releaseGroupId);

        return null;
    }

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata
    {
        $this->hydrated[] = $identifiers;

        return $identifiers->releaseId === null ? CandidateMetadata::empty() : ($this->albums[$identifiers->releaseId] ?? CandidateMetadata::empty());
    }

    public function album(
        string $releaseId,
        string $releaseGroupId,
        string $title,
        int $firstLengthMs,
        ?string $firstRecordingId = null,
    ): CandidateMetadata {
        $releaseTracks = [];
        $titles = ['Amber Signal', 'Copper Tide', 'Glass Harbour'];
        $lengths = [$firstLengthMs, 207_000, 239_000];
        foreach ($titles as $index => $trackTitle) {
            $recordingId = $index === 0 && $firstRecordingId !== null ? $firstRecordingId : sprintf('c0ffee00-0000-4000-8000-%012d', $index + 1);
            $releaseTracks[] = [
                'musicBrainzReleaseTrackId' => sprintf('c0ffee00-0000-4000-8000-%012d', 500 + $index),
                'title' => $trackTitle,
                'position' => $index + 1,
                'number' => (string) ($index + 1),
                'lengthMs' => $lengths[$index],
                'artistCredit' => 'Example Artist',
                'recording' => [
                    'recordingId' => $recordingId,
                    'title' => $trackTitle,
                    'artistCredit' => 'Example Artist',
                    'lengthMs' => $lengths[$index],
                    'video' => false,
                    'isrcs' => [],
                    'releaseIds' => [$releaseId],
                    'releaseGroupIds' => [$releaseGroupId],
                    'providerScore' => null,
                    'sources' => ['fixture'],
                ],
            ];
        }

        return new CandidateMetadata([], [[
            'releaseId' => $releaseId,
            'title' => $title,
            'artistCredit' => 'Example Artist',
            'releaseGroupId' => $releaseGroupId,
            'status' => 'Official',
            'date' => '2020-03-01',
            'country' => null,
            'barcode' => null,
            'labels' => [],
            'media' => [[
                'position' => 1,
                'title' => null,
                'format' => null,
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
            'firstReleaseDate' => '2020-03-01',
        ]]);
    }
}

final readonly class FixedFingerprintTestGenerator implements CandidateGenerator
{
    public function __construct(private CandidatePool $pool) {}

    public function generate(AudioEvidenceSet $evidence): CandidatePool
    {
        unset($evidence);

        return $this->pool;
    }
}
