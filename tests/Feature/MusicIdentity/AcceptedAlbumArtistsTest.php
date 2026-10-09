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
 * Issue #313, section D: an accepted album's credited artists, their canonical names and their
 * "Artist name" and "Search hint" aliases are stored once per artist, linked to the decision, and
 * searched through the release document's artist field; a changed artist re-syncs every other
 * release whose current decision credits it.
 */
final class AcceptedAlbumArtistsTest extends TestCase
{
    private const string GROUP = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const string ARTIST = '99999999-9999-4999-8999-999999999999';

    private const string GUEST = '88888888-8888-4888-8888-888888888888';

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
    public function an_accepted_album_stores_its_artists_aliases_and_links_in_credit_order(): void
    {
        $this->decide(1, IdentificationStatus::AcceptedReleaseGroup, [$this->artist(), $this->guest()]);

        $this->assertSame([[self::ARTIST, 'Canonical Example Band'], [self::GUEST, 'Guest Artist']], $this->artists());
        $this->assertSame([['Altname Ensemble', 'artist_name'], ['Hintword Band', 'search_hint']], $this->aliases(self::ARTIST));
        $this->assertSame([self::ARTIST, self::GUEST], $this->links(1));
        $this->assertSame('Example Artist Canonical Example Band Guest Artist Altname Ensemble Hintword Band', ReleaseIndexProjection::forId(1)['artist']);
    }

    #[Test]
    public function other_states_write_no_artist_alias_or_link_rows(): void
    {
        foreach ([IdentificationStatus::AcceptedRecording, IdentificationStatus::NeedsReview, IdentificationStatus::Conflicted,
            IdentificationStatus::Unresolved, IdentificationStatus::RetryableError] as $status) {
            $this->decide(1, $status, [$this->artist()]);

            $this->assertSame([], $this->artists(), $status->value);
            $this->assertSame(0, DB::table('release_music_identification_artists')->count(), $status->value);
        }
    }

    #[Test]
    public function a_changed_artist_re_syncs_only_the_releases_whose_current_decision_credits_it(): void
    {
        $this->decide(1, IdentificationStatus::AcceptedEdition, [$this->artist()]);
        $this->decide(3, IdentificationStatus::AcceptedEdition, [$this->artist()]);
        $this->decide(3, IdentificationStatus::Unresolved, []);

        // The second response renames the artist, drops Hintword Band and adds Newword Collective.
        $changed = ['artistId' => self::ARTIST, 'name' => 'Canonical Example Band Renamed', 'aliases' => [
            ['name' => 'Altname Ensemble', 'type' => 'artist_name'], ['name' => 'Newword Collective', 'type' => 'search_hint'],
        ]];
        $this->decide(2, IdentificationStatus::AcceptedEdition, [$changed]);

        $this->assertSame([[self::ARTIST, 'Canonical Example Band Renamed']], $this->artists());
        $this->assertSame([['Altname Ensemble', 'artist_name'], ['Newword Collective', 'search_hint']], $this->aliases(self::ARTIST));
        Search::shouldHaveReceived('updateRelease')->with(1)->times(2);
        Search::shouldHaveReceived('updateRelease')->with(3)->times(2);
        $artist = (string) ReleaseIndexProjection::forId(1)['artist'];
        $this->assertStringContainsString('Newword Collective', $artist);
        $this->assertStringNotContainsString('Hintword', $artist);

        $this->decide(2, IdentificationStatus::AcceptedEdition, [$changed]);
        Search::shouldHaveReceived('updateRelease')->with(1)->times(2);
    }

    #[Test]
    public function a_replacing_album_drops_the_old_names_and_a_withdrawal_restores_the_legacy_artist(): void
    {
        DB::table('musicinfo')->insert(['id' => 7, 'title' => 'Legacy Album', 'artist' => 'Legacy Artist']);
        DB::table('releases')->where('id', 1)->update(['musicinfo_id' => 7]);
        $this->decide(1, IdentificationStatus::AcceptedEdition, [$this->artist()]);
        $this->decide(1, IdentificationStatus::AcceptedEdition, [$this->guest()]);

        $artist = (string) ReleaseIndexProjection::forId(1)['artist'];
        $this->assertStringContainsString('Guest Artist', $artist);
        $this->assertStringNotContainsString('Canonical', $artist);
        $this->assertStringNotContainsString('Altname', $artist);

        $this->decide(1, IdentificationStatus::Unresolved, []);
        $this->assertSame('Legacy Artist', ReleaseIndexProjection::forId(1)['artist']);
    }

    /** @return array{artistId: string, name: string, aliases: list<array{name: string, type: 'artist_name'|'search_hint'}>} */
    private function artist(): array
    {
        return ['artistId' => self::ARTIST, 'name' => 'Canonical Example Band', 'aliases' => [
            ['name' => 'Altname Ensemble', 'type' => 'artist_name'], ['name' => 'Hintword Band', 'type' => 'search_hint'],
        ]];
    }

    /** @return array{artistId: string, name: string, aliases: list<array{name: string, type: 'artist_name'|'search_hint'}>} */
    private function guest(): array
    {
        return ['artistId' => self::GUEST, 'name' => 'Guest Artist', 'aliases' => []];
    }

    /** @param list<array{artistId: string, name: string, aliases: list<array{name: string, type: 'artist_name'|'search_hint'}>}> $artists */
    private function decide(int $releaseId, IdentificationStatus $status, array $artists): void
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
                    $album => new CandidateIdentity(releaseId: $edition ? '11111111-1111-4111-8111-111111111111' : null, releaseGroupId: self::GROUP),
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
                    artists: $artists,
                ),
            ),
            nextAttemptAt: $retryable ? (new MusicIdentityRetryPolicy)->nextAttemptAt(0) : null,
        );
    }

    /** @return list<array{0: string, 1: string}> */
    private function artists(): array
    {
        return DB::table('musicbrainz_artists')->orderByDesc('musicbrainz_artist_id')->get()
            ->map(static fn (object $row): array => [(string) $row->musicbrainz_artist_id, (string) $row->name])->all();
    }

    /** @return list<array{0: string, 1: string}> */
    private function aliases(string $artistId): array
    {
        return DB::table('musicbrainz_artist_aliases')->where('musicbrainz_artist_id', $artistId)->orderBy('position')->get()
            ->map(static fn (object $row): array => [(string) $row->name, (string) $row->type])->all();
    }

    /** @return list<string> */
    private function links(int $releaseId): array
    {
        $decision = DB::table('release_music_identifications')->where('releases_id', $releaseId)->max('id');

        return DB::table('release_music_identification_artists')->where('release_music_identifications_id', $decision)->orderBy('position')
            ->pluck('musicbrainz_artist_id')->map(static fn (mixed $id): string => (string) $id)->all();
    }
}
