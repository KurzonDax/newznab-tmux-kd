<?php

declare(strict_types=1);

namespace Tests\Feature\MusicIdentity;

use App\Facades\Search;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\Persistence\MusicIdentityLeaseManager;
use App\Services\Search\Support\ReleaseIndexProjection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MusicIdentityDecisionFixtures;
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
    use MusicIdentityDecisionFixtures;

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
            $this->assertStringContainsString('Example Artist', $document['artist']);
            $this->assertStringNotContainsString('Candidate Album', json_encode($document, JSON_THROW_ON_ERROR));
        }
        $this->assertSame('', $unlinkedDocument['album_title']);
        $this->assertSame('Example Artist', $unlinkedDocument['artist']);
        $this->assertSame('Legacy Album', $legacyDocument['album_title'], 'a recording acceptance leaves the legacy album match');
        $this->assertStringContainsString('Legacy Artist', $legacyDocument['artist'], 'the legacy artist still matches');
        $this->assertStringContainsString('Example Artist', $legacyDocument['artist'], 'the recording artist matches too');
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

    /** @return array<string, mixed> */
    private function document(int $releaseId): array
    {
        $document = ReleaseIndexProjection::forId($releaseId);
        $this->assertNotNull($document);

        return $document;
    }
}
