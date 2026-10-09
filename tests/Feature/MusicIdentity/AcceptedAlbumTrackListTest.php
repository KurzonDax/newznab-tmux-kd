<?php

declare(strict_types=1);

namespace Tests\Feature\MusicIdentity;

use App\Facades\Search;
use App\Models\ReleaseAudioEvidence;
use App\Services\MusicIdentity\DTO\AcceptedMusicText;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateIdentity;
use App\Services\MusicIdentity\DTO\DecisionReason;
use App\Services\MusicIdentity\DTO\IdentificationDecision;
use App\Services\MusicIdentity\Enums\AcceptedIdentityScope;
use App\Services\MusicIdentity\Enums\IdentificationBand;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\MusicIdentityRetryPolicy;
use App\Services\MusicIdentity\Persistence\IdentificationDecisionStore;
use App\Services\Search\Support\ReleaseIndexProjection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * Issue #313, section B: an accepted album's MusicBrainz track list is stored once per MusicBrainz
 * release, named by the decision, and read by the release search; a changed list re-syncs every
 * other release whose current decision names that release.
 */
final class AcceptedAlbumTrackListTest extends TestCase
{
    private const string GROUP = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const string EDITION = '11111111-1111-4111-8111-111111111111';

    private const string ALIGNED = '22222222-2222-4222-8222-222222222222';

    /** @var array<int, int> each release's newest evidence revision */
    private array $revisions = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['music-identity.algorithm_version' => 'music-identity-v3']);
        Carbon::setTestNow('2026-10-08 12:00:00');
        Search::spy();
        foreach ([
            'releases', 'usenet_groups', 'categories', 'root_categories', 'movieinfo', 'musicinfo', 'consoleinfo', 'gamesinfo',
            'bookinfo', 'videos', 'tv_episodes', 'release_nfos', 'video_data', 'media_infos', 'release_files', 'audio_data',
            'release_subtitles', 'anidb_titles', 'media_info_probes', 'media_info_tracks', 'release_audio_tags', 'audio_genres',
            'release_audio_genres', 'release_audio_evidence', 'release_music_identifications', 'release_music_candidate_attempts',
            'musicbrainz_release_group_genres', 'musicbrainz_release_tracks', 'musicbrainz_artists', 'musicbrainz_artist_aliases',
            'release_music_identification_artists',
        ] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        foreach ([1, 2, 3] as $releaseId) {
            DB::table('releases')->insert(['id' => $releaseId, 'name' => 'Example.Release.'.$releaseId, 'searchname' => 'Example.Release.'.$releaseId,
                'guid' => md5((string) $releaseId), 'categories_id' => 3040, 'postdate' => now(), 'adddate' => now()]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function an_accepted_edition_stores_its_track_list_once_in_musicbrainz_order(): void
    {
        $this->decide(1, IdentificationStatus::AcceptedEdition, self::EDITION, $this->twoMedia());

        $this->assertSame([
            [1, 1, '1 - Example Opening', 125_600, 'Example Artist'],
            [1, 2, 'Example Song', 59_400, 'Example Artist feat. Guest Artist'],
            [2, 1, 'Example Closer', 61_000, 'Example Artist'],
        ], $this->stored(self::EDITION));
        $this->assertSame(self::EDITION, $this->decisionReleaseId(1));
    }

    #[Test]
    public function a_release_group_acceptance_names_its_aligned_release_and_stores_only_its_tracks(): void
    {
        $this->decide(1, IdentificationStatus::AcceptedReleaseGroup, self::ALIGNED, $this->twoMedia());

        $this->assertSame(self::ALIGNED, $this->decisionReleaseId(1));
        $this->assertSame(self::GROUP, DB::table('release_music_identifications')->where('releases_id', 1)->value('musicbrainz_release_group_id'));
        $this->assertCount(3, $this->stored(self::ALIGNED));
        $this->assertSame([self::ALIGNED], DB::table('musicbrainz_release_tracks')->distinct()->pluck('musicbrainz_release_id')->all());
    }

    #[Test]
    public function other_states_store_no_tracks_and_name_no_release(): void
    {
        foreach ([IdentificationStatus::AcceptedRecording, IdentificationStatus::NeedsReview, IdentificationStatus::Conflicted,
            IdentificationStatus::Unresolved, IdentificationStatus::RetryableError] as $status) {
            $this->decide(1, $status, self::EDITION, $this->twoMedia());

            $this->assertNull($this->decisionReleaseId(1), $status->value);
            $this->assertSame([], $this->stored(self::EDITION), $status->value);
        }
    }

    #[Test]
    public function an_album_without_a_listed_track_keeps_the_stored_list(): void
    {
        $this->decide(1, IdentificationStatus::AcceptedEdition, self::EDITION, $this->twoMedia());
        $this->decide(2, IdentificationStatus::AcceptedEdition, self::EDITION, []);

        $this->assertCount(3, $this->stored(self::EDITION));
    }

    #[Test]
    public function a_changed_shared_list_re_syncs_only_the_releases_whose_current_decision_names_it(): void
    {
        $this->decide(1, IdentificationStatus::AcceptedEdition, self::EDITION, $this->twoMedia());
        $this->decide(3, IdentificationStatus::AcceptedEdition, self::EDITION, $this->twoMedia());
        $this->decide(3, IdentificationStatus::Unresolved, null, []);
        $this->assertStringContainsString('Example Song', (string) ReleaseIndexProjection::forId(1)['music_tracks']);

        // The second response drops track 2 and retitles track 1.
        $changed = $this->twoMedia();
        unset($changed[1]);
        $changed[0]['title'] = 'Example Opening Revised';
        $this->decide(2, IdentificationStatus::AcceptedEdition, self::EDITION, array_values($changed));

        $this->assertSame([[1, 1, 'Example Opening Revised', 125_600, 'Example Artist'], [2, 1, 'Example Closer', 61_000, 'Example Artist']], $this->stored(self::EDITION));
        Search::shouldHaveReceived('updateRelease')->with(1)->times(2);
        Search::shouldHaveReceived('updateRelease')->with(3)->times(2);
        $document = (string) ReleaseIndexProjection::forId(1)['music_tracks'];
        $this->assertStringContainsString('Example Opening Revised', $document);
        $this->assertStringNotContainsString('Example Song', $document);
        $this->assertStringNotContainsString('Guest Artist', $document);

        // The same list again changes nothing, so no other release is re-synced.
        $this->decide(2, IdentificationStatus::AcceptedEdition, self::EDITION, array_values($changed));
        Search::shouldHaveReceived('updateRelease')->with(1)->times(2);
    }

    #[Test]
    public function the_document_holds_the_track_titles_and_distinct_track_artist_credits(): void
    {
        $this->decide(1, IdentificationStatus::AcceptedEdition, self::EDITION, $this->twoMedia());

        $words = explode(' ', (string) ReleaseIndexProjection::forId(1)['music_tracks']);
        foreach (['Opening', 'Song', 'Closer', 'Guest'] as $word) {
            $this->assertContains($word, $words);
        }
        $this->assertSame(1, substr_count((string) ReleaseIndexProjection::forId(1)['music_tracks'], 'feat.'), 'each credit once');
    }

    /** @return list<array{mediumPosition: int, trackPosition: int, title: string, lengthMs: int|null, artistCredit: string|null}> */
    private function twoMedia(): array
    {
        return [
            ['mediumPosition' => 1, 'trackPosition' => 1, 'title' => '1 - Example Opening', 'lengthMs' => 125_600, 'artistCredit' => 'Example Artist'],
            ['mediumPosition' => 1, 'trackPosition' => 2, 'title' => 'Example Song', 'lengthMs' => 59_400, 'artistCredit' => 'Example Artist feat. Guest Artist'],
            ['mediumPosition' => 2, 'trackPosition' => 1, 'title' => 'Example Closer', 'lengthMs' => 61_000, 'artistCredit' => 'Example Artist'],
        ];
    }

    /** @param list<array{mediumPosition: int, trackPosition: int, title: string, lengthMs: int|null, artistCredit: string|null}> $tracks */
    private function decide(int $releaseId, IdentificationStatus $status, ?string $musicBrainzReleaseId, array $tracks): void
    {
        $revision = $this->revisions[$releaseId] = ($this->revisions[$releaseId] ?? 0) + 1;
        $evidence = ReleaseAudioEvidence::query()->create([
            'releases_id' => $releaseId, 'revision' => $revision, 'evidence_hash' => hash('sha256', $releaseId.':'.$revision),
            'schema_version' => 1, 'provenance' => 'captured', 'release_snapshot' => [], 'archive_manifest_complete' => true,
            'nzb_manifest' => [], 'archive_manifest' => [], 'sidecar_manifest' => [], 'captured_at' => now(),
        ]);
        $edition = $status === IdentificationStatus::AcceptedEdition;
        $album = $edition || $status === IdentificationStatus::AcceptedReleaseGroup;
        $retryable = $status === IdentificationStatus::RetryableError;

        (new IdentificationDecisionStore)->persist(
            releaseId: $releaseId,
            evidence: new AudioEvidenceSet((int) $evidence->id, (string) $evidence->evidence_hash, null, null, null, null, []),
            decision: new IdentificationDecision(
                status: $status,
                score: $retryable ? 0 : 97,
                band: $retryable ? IdentificationBand::Unresolved : IdentificationBand::Verified,
                acceptedIdentity: match (true) {
                    $album => new CandidateIdentity(releaseId: $edition ? $musicBrainzReleaseId : null, releaseGroupId: self::GROUP),
                    $status === IdentificationStatus::AcceptedRecording => new CandidateIdentity(recordingId: '33333333-3333-4333-8333-333333333333'),
                    default => null,
                },
                reasons: [new DecisionReason('fixture', 'fixture')],
                candidates: [],
                runnerUpMargin: null,
                algorithmVersion: 'music-identity-v3',
                resolverVersion: 'resolver-v1',
                normalizerVersion: 'normalizer-v1',
                scorerVersion: 'whole-release-v1',
                policyVersion: 'shadow-v1',
                operationalError: $retryable ? 'mirror unavailable' : null,
                // Any state may carry text; the store keeps it only for an accepted album.
                acceptedText: new AcceptedMusicText(
                    $edition ? AcceptedIdentityScope::Edition : AcceptedIdentityScope::ReleaseGroup,
                    'Example Album',
                    artistCredit: 'Example Artist',
                    releaseId: $musicBrainzReleaseId,
                    tracks: $tracks,
                ),
            ),
            nextAttemptAt: $retryable ? (new MusicIdentityRetryPolicy)->nextAttemptAt(0) : null,
        );
    }

    private function decisionReleaseId(int $releaseId): ?string
    {
        $value = DB::table('release_music_identifications')->where('releases_id', $releaseId)->orderByDesc('id')->value('musicbrainz_release_id');

        return $value === null ? null : (string) $value;
    }

    /** @return list<array{0: int, 1: int, 2: string, 3: int|null, 4: string|null}> */
    private function stored(string $musicBrainzReleaseId): array
    {
        return DB::table('musicbrainz_release_tracks')->where('musicbrainz_release_id', $musicBrainzReleaseId)
            ->orderBy('medium_position')->orderBy('track_position')->get()
            ->map(static fn (object $row): array => [(int) $row->medium_position, (int) $row->track_position, (string) $row->title,
                $row->length_ms === null ? null : (int) $row->length_ms, $row->artist_credit === null ? null : (string) $row->artist_credit])->all();
    }
}
