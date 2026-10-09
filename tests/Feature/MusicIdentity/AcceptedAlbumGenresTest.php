<?php

declare(strict_types=1);

namespace Tests\Feature\MusicIdentity;

use App\Facades\Search;
use App\Models\ReleaseAudioEvidence;
use App\Services\AudioProcessing\AudioGenres;
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
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * Issue #313, section A: an accepted MusicBrainz album's release group genres are stored once per
 * group in vote order and replace the tag genres in a release's genre rows; the tag genres stay the
 * fallback.
 */
final class AcceptedAlbumGenresTest extends TestCase
{
    private const string GROUP = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const string OTHER_GROUP = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private const string EDITION = '11111111-1111-4111-8111-111111111111';

    /** @var list<array{name: string, count: int}> */
    private const array GENRES = [
        ['name' => 'rock', 'count' => 5],
        ['name' => 'new wave', 'count' => 2],
        ['name' => 'indie rock', 'count' => 5],
    ];

    /** @var array<int, int> each release's newest evidence revision */
    private array $revisions = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['music-identity.algorithm_version' => 'music-identity-v3']);
        Search::spy();
        foreach (['release_audio_tags', 'audio_genres', 'release_audio_genres', 'release_audio_evidence', 'release_music_identifications',
            'release_music_candidate_attempts', 'musicbrainz_release_group_genres', 'musicbrainz_release_tracks', 'musicbrainz_artists',
            'musicbrainz_artist_aliases', 'release_music_identification_artists'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        ProductionTables::fromAuthority()->create('releases', ['id']);
        DB::table('releases')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);
        // A band release tagged Rock is stored first, so MusicBrainz's "rock" resolves to its row.
        $this->tag(2, 'Rock');
        $this->tag(1, 'Alternatif et Indé');
    }

    #[Test]
    public function an_accepted_release_group_stores_its_genres_in_vote_order_and_they_become_the_releases_genres(): void
    {
        $this->decide(1, IdentificationStatus::AcceptedReleaseGroup, self::GROUP, self::GENRES);

        $this->assertSame(['indie rock', 'Rock', 'new wave'], $this->groupGenres(self::GROUP));
        $this->assertSame(['indie rock', 'Rock', 'new wave'], $this->releaseGenres(1));
        $this->assertSame(1, DB::table('audio_genres')->whereRaw('LOWER(name) = ?', ['rock'])->count(), 'rock shares the stored Rock row');
        $this->assertSame(['Rock'], $this->releaseGenres(2), 'a release without the album keeps its tag genres');
    }

    #[Test]
    public function an_accepted_edition_stores_its_release_groups_genres(): void
    {
        $this->decide(1, IdentificationStatus::AcceptedEdition, self::GROUP, [['name' => 'synth-pop', 'count' => 3]]);

        $this->assertSame(['synth-pop'], $this->groupGenres(self::GROUP));
        $this->assertSame(['synth-pop'], $this->releaseGenres(1));
    }

    #[Test]
    public function other_states_write_no_group_rows_and_keep_the_tag_genres(): void
    {
        foreach ([IdentificationStatus::AcceptedRecording, IdentificationStatus::NeedsReview, IdentificationStatus::Conflicted,
            IdentificationStatus::Unresolved, IdentificationStatus::RetryableError] as $status) {
            $this->decide(1, $status, self::GROUP, self::GENRES);

            $this->assertSame([], $this->groupGenres(self::GROUP), $status->value);
            $this->assertSame(['Alternatif et Indé'], $this->releaseGenres(1), $status->value);
        }
    }

    #[Test]
    public function a_second_album_decision_replaces_the_groups_rows_and_the_other_release_follows(): void
    {
        $this->tag(3, 'Electronic');
        $this->decide(1, IdentificationStatus::AcceptedReleaseGroup, self::GROUP, self::GENRES);
        $this->decide(3, IdentificationStatus::AcceptedReleaseGroup, self::GROUP, [['name' => 'synth-pop', 'count' => 3], ['name' => 'rock', 'count' => 1]]);

        $this->assertSame(['synth-pop', 'Rock'], $this->groupGenres(self::GROUP));
        $this->assertSame(2, DB::table('musicbrainz_release_group_genres')->where('musicbrainz_release_group_id', self::GROUP)->count());
        $this->assertSame(['synth-pop', 'Rock'], $this->releaseGenres(3));
        $this->assertSame(['synth-pop', 'Rock'], $this->releaseGenres(1), 'the first release shows the group\'s new genres');
    }

    #[Test]
    public function a_release_whose_current_decision_no_longer_accepts_the_group_is_not_re_derived(): void
    {
        $this->decide(1, IdentificationStatus::AcceptedReleaseGroup, self::GROUP, self::GENRES);
        $this->decide(1, IdentificationStatus::Unresolved, null, null);
        DB::table('release_audio_genres')->where('releases_id', 1)->delete();

        $this->decide(3, IdentificationStatus::AcceptedReleaseGroup, self::GROUP, [['name' => 'synth-pop', 'count' => 3]]);

        $this->assertSame([], $this->releaseGenres(1), 'a withdrawn release is left alone');
    }

    #[Test]
    public function a_lookup_without_genres_leaves_the_group_without_rows_and_the_tag_genres_in_place(): void
    {
        $this->decide(1, IdentificationStatus::AcceptedReleaseGroup, self::GROUP, self::GENRES);
        $this->decide(1, IdentificationStatus::AcceptedReleaseGroup, self::GROUP, []);

        $this->assertSame([], $this->groupGenres(self::GROUP));
        $this->assertSame(['Alternatif et Indé'], $this->releaseGenres(1));
    }

    #[Test]
    public function the_genre_rows_follow_each_change_of_the_current_decision(): void
    {
        $this->decide(1, IdentificationStatus::AcceptedReleaseGroup, self::GROUP, self::GENRES);
        $this->decide(1, IdentificationStatus::Unresolved, null, null);
        $this->assertSame(['Alternatif et Indé'], $this->releaseGenres(1), 'a completed non-album decision returns the tag genres');

        $this->decide(1, IdentificationStatus::AcceptedReleaseGroup, self::GROUP, self::GENRES);
        $this->decide(1, IdentificationStatus::AcceptedReleaseGroup, self::OTHER_GROUP, [['name' => 'synth-pop', 'count' => 3]]);
        $this->assertSame(['synth-pop'], $this->releaseGenres(1), 'another album replaces them');

        $this->decide(1, IdentificationStatus::AcceptedReleaseGroup, self::GROUP, self::GENRES);
        $this->decide(1, IdentificationStatus::AcceptedReleaseGroup, self::OTHER_GROUP, []);
        $this->assertSame(['Alternatif et Indé'], $this->releaseGenres(1), 'an album without MusicBrainz genres returns the tag genres');

        $this->decide(1, IdentificationStatus::AcceptedRecording, null, null);
        $this->assertSame(['Alternatif et Indé'], $this->releaseGenres(1), 'a recording acceptance leaves the tag genres');
    }

    #[Test]
    public function writing_the_tag_genres_again_keeps_the_albums_genres(): void
    {
        $this->decide(1, IdentificationStatus::AcceptedReleaseGroup, self::GROUP, self::GENRES);
        $genres = new AudioGenres;

        $genres->replaceForTag(1, 'Jazz', static function (): void {
            DB::table('release_audio_tags')->where('releases_id', 1)->update(['genre' => 'Jazz']);
        });

        $this->assertSame(['indie rock', 'Rock', 'new wave'], $this->releaseGenres(1));
    }

    #[Test]
    public function a_tag_write_reads_a_decision_committed_while_it_waited_for_the_release_lock(): void
    {
        $this->decide(3, IdentificationStatus::AcceptedReleaseGroup, self::GROUP, self::GENRES);

        // The tag write's own writes run first under the release-row lock; a decision committed
        // before the genres are computed (simulated inside that window) is the one they follow.
        (new AudioGenres)->replaceForTag(1, 'Jazz', static function (): void {
            DB::table('release_audio_tags')->where('releases_id', 1)->update(['genre' => 'Jazz']);
            DB::table('release_music_identifications')->insert([
                'releases_id' => 1, 'evidence_hash' => str_repeat('b', 64), 'state' => IdentificationStatus::AcceptedReleaseGroup->value,
                'musicbrainz_release_group_id' => self::GROUP, 'algorithm_version' => 'music-identity-v3',
            ]);
        });

        $this->assertSame(['indie rock', 'Rock', 'new wave'], $this->releaseGenres(1));
    }

    #[Test]
    public function a_release_without_a_tag_row_has_no_genre_rows(): void
    {
        DB::table('release_audio_tags')->where('releases_id', 1)->delete();
        DB::table('release_audio_genres')->where('releases_id', 1)->delete();

        $this->decide(1, IdentificationStatus::AcceptedReleaseGroup, self::GROUP, self::GENRES);

        $this->assertSame(['indie rock', 'Rock', 'new wave'], $this->groupGenres(self::GROUP));
        $this->assertSame([], $this->releaseGenres(1));
    }

    #[Test]
    public function a_release_tagged_unknown_takes_its_albums_genres(): void
    {
        $this->tag(3, 'Unknown');
        $this->assertSame([], $this->releaseGenres(3));

        $this->decide(3, IdentificationStatus::AcceptedReleaseGroup, self::GROUP, self::GENRES);

        $this->assertSame(['indie rock', 'Rock', 'new wave'], $this->releaseGenres(3));
    }

    private function tag(int $releaseId, string $genre): void
    {
        (new AudioGenres)->replaceForTag($releaseId, $genre, static function () use ($releaseId, $genre): void {
            DB::table('release_audio_tags')->updateOrInsert(['releases_id' => $releaseId], ['genre' => $genre]);
        });
    }

    /** @param list<array{name: string, count: int}>|null $genres */
    private function decide(int $releaseId, IdentificationStatus $status, ?string $groupId, ?array $genres): void
    {
        $revision = $this->revisions[$releaseId] = ($this->revisions[$releaseId] ?? 0) + 1;
        $evidence = ReleaseAudioEvidence::query()->create([
            'releases_id' => $releaseId, 'revision' => $revision, 'evidence_hash' => hash('sha256', $releaseId.':'.$revision),
            'schema_version' => 1, 'provenance' => 'captured', 'release_snapshot' => [], 'archive_manifest_complete' => true,
            'nzb_manifest' => [], 'archive_manifest' => [], 'sidecar_manifest' => [], 'captured_at' => now(),
        ]);
        $album = in_array($status, [IdentificationStatus::AcceptedReleaseGroup, IdentificationStatus::AcceptedEdition], true);
        $edition = $status === IdentificationStatus::AcceptedEdition;
        $retryable = $status === IdentificationStatus::RetryableError;

        (new IdentificationDecisionStore)->persist(
            releaseId: $releaseId,
            evidence: new AudioEvidenceSet((int) $evidence->id, (string) $evidence->evidence_hash, null, null, null, null, []),
            decision: new IdentificationDecision(
                status: $status,
                score: $retryable ? 0 : 97,
                band: $retryable ? IdentificationBand::Unresolved : IdentificationBand::Verified,
                acceptedIdentity: match (true) {
                    $album => new CandidateIdentity(releaseId: $edition ? self::EDITION : null, releaseGroupId: $groupId),
                    $status === IdentificationStatus::AcceptedRecording => new CandidateIdentity(recordingId: '22222222-2222-4222-8222-000000000001'),
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
                acceptedText: $album ? new AcceptedMusicText($edition ? AcceptedIdentityScope::Edition : AcceptedIdentityScope::ReleaseGroup, 'Example Album', artistCredit: 'Example Artist') : null,
                releaseGroupGenres: $genres,
            ),
            nextAttemptAt: $retryable ? (new MusicIdentityRetryPolicy)->nextAttemptAt(0) : null,
        );
    }

    /** @return list<string> */
    private function groupGenres(string $groupId): array
    {
        return DB::table('musicbrainz_release_group_genres as g')->join('audio_genres as a', 'a.id', '=', 'g.audio_genres_id')
            ->where('g.musicbrainz_release_group_id', $groupId)->orderBy('g.position')->pluck('a.name')->all();
    }

    /** @return list<string> */
    private function releaseGenres(int $releaseId): array
    {
        return DB::table('release_audio_genres as g')->join('audio_genres as a', 'a.id', '=', 'g.audio_genres_id')
            ->where('g.releases_id', $releaseId)->orderBy('g.position')->pluck('a.name')->all();
    }
}
