<?php

declare(strict_types=1);

namespace Tests\Feature\MusicIdentity;

use App\Facades\Search;
use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseMusicIdentification;
use App\Services\MusicIdentity\CoverArt\AlbumCoverImages;
use App\Services\MusicIdentity\CurrentMusicIdentityReader;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateIdentity;
use App\Services\MusicIdentity\DTO\DecisionReason;
use App\Services\MusicIdentity\DTO\IdentificationDecision;
use App\Services\MusicIdentity\Enums\IdentificationBand;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\MusicIdentityRetryPolicy;
use App\Services\MusicIdentity\Persistence\IdentificationDecisionStore;
use App\Services\MusicIdentity\Persistence\MusicIdentityLeaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The shared "current music identity" rule (issue #1015): the completed decision for the newest
 * evidence under the configured algorithm version, else the release's newest completed decision;
 * only an accepted album gives a cover and the MusicBrainz link.
 */
final class CurrentMusicIdentityTest extends TestCase
{
    private const string GROUP = '11111111-1111-4111-8111-111111111111';

    private const string OTHER_GROUP = '22222222-2222-4222-8222-222222222222';

    private const string EDITION = '33333333-3333-4333-8333-333333333333';

    private const int RELEASE = 10;

    private string $covers = '';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'music-identity.algorithm_version' => 'music-identity-v1',
            'music-identity.retry.initial_seconds' => 60,
            'music-identity.retry.maximum_seconds' => 120,
        ]);
        Search::spy(); // decision writes re-sync the release search document, which these tests do not build
        DB::purge();
        DB::reconnect();
        Carbon::setTestNow('2026-10-08 12:00:00');

        ProductionTables::fromAuthority()->create('releases', ['id']);
        foreach (['*_create_release_audio_evidence_tables.php', '*_create_release_music_identification_tables.php', '*_create_music_cover_art_lookups_table.php', '*_add_accepted_music_text_to_release_music_identifications.php'] as $pattern) {
            $this->migration($pattern)->up();
        }
        DB::table('releases')->insert([['id' => self::RELEASE], ['id' => self::RELEASE + 1]]);
        $this->covers = $this->makeTempDirectory('current-music-identity-covers');
        config(['nntmux_settings.covers_path' => $this->covers]);
        $this->storedCover('release-group', self::GROUP);
        $this->storedCover('release-group', self::OTHER_GROUP);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function an_algorithm_version_bump_with_no_new_row_keeps_the_cover_and_link(): void
    {
        $evidence = $this->evidence(self::RELEASE, 1);
        $this->complete($evidence, IdentificationStatus::AcceptedReleaseGroup, groupId: self::GROUP);

        config(['music-identity.algorithm_version' => 'music-identity-v2']);

        $this->assertSame($this->album(self::GROUP), $this->display(self::RELEASE));
    }

    /** @return array<string, array{string}> the two ways a release gets a new target row */
    public static function newTargets(): array
    {
        return ['an algorithm version bump' => ['version'], 'a newer evidence revision' => ['evidence']];
    }

    #[Test]
    #[DataProvider('newTargets')]
    public function unfinished_attempts_keep_the_prior_decision_until_a_completed_non_album_decision_withdraws_it(string $variant): void
    {
        $target = $this->acceptedThenNewTarget($variant);

        $this->assertSame(IdentificationStatus::Pending, $this->lease($target, 'worker-a')->state);
        $this->assertSame($this->album(self::GROUP), $this->display(self::RELEASE), 'a pending lease is no withdrawal');
        $this->complete($target, IdentificationStatus::RetryableError, leaseToken: 'worker-a');
        $this->assertSame($this->album(self::GROUP), $this->display(self::RELEASE), 'a retryable error is no withdrawal');

        Carbon::setTestNow(now()->addHour());
        $this->lease($target, 'worker-b');
        $this->complete($target, IdentificationStatus::Unresolved, leaseToken: 'worker-b');
        $this->assertSame(['link' => '', 'cover' => null], $this->display(self::RELEASE), 'a completed non-album decision withdraws both');

        $later = $this->evidence(self::RELEASE, 9);
        $this->lease($later, 'worker-c');
        $this->assertSame(['link' => '', 'cover' => null], $this->display(self::RELEASE), 'a later unfinished attempt never resurrects the older album');
        $this->complete($later, IdentificationStatus::RetryableError, leaseToken: 'worker-c');
        $this->assertSame(['link' => '', 'cover' => null], $this->display(self::RELEASE));
    }

    #[Test]
    #[DataProvider('newTargets')]
    public function a_completed_decision_naming_another_album_replaces_the_cover_and_link(string $variant): void
    {
        $target = $this->acceptedThenNewTarget($variant);

        $this->lease($target, 'worker-a');
        $this->complete($target, IdentificationStatus::RetryableError, leaseToken: 'worker-a');
        $this->assertSame($this->album(self::GROUP), $this->display(self::RELEASE));
        Carbon::setTestNow(now()->addHour());
        $this->lease($target, 'worker-b');
        $this->complete($target, IdentificationStatus::AcceptedReleaseGroup, groupId: self::OTHER_GROUP, leaseToken: 'worker-b');

        $this->assertSame($this->album(self::OTHER_GROUP), $this->display(self::RELEASE));
    }

    #[Test]
    public function with_no_completed_decision_unfinished_attempts_show_the_placeholder_and_no_link(): void
    {
        $evidence = $this->evidence(self::RELEASE, 1);
        $this->assertNotNull((new MusicIdentityLeaseManager)->acquire($evidence, 'worker-a'));
        $this->assertSame(['link' => '', 'cover' => null], $this->display(self::RELEASE));

        $this->complete($evidence, IdentificationStatus::RetryableError, leaseToken: 'worker-a');
        $this->assertSame(['link' => '', 'cover' => null], $this->display(self::RELEASE));
        $this->assertSame(['link' => '', 'cover' => null], $this->display(self::RELEASE + 1), 'no decision at all');
    }

    #[Test]
    public function an_accepted_edition_shows_its_stored_lookup_and_the_release_group_link_and_a_page_reads_in_constant_queries(): void
    {
        $this->storedCover('release', self::EDITION, imageId: self::OTHER_GROUP);
        $this->complete($this->evidence(self::RELEASE, 1), IdentificationStatus::AcceptedEdition, releaseId: self::EDITION, groupId: self::GROUP);
        $this->complete($this->evidence(self::RELEASE + 1, 1), IdentificationStatus::AcceptedRecording, groupId: self::OTHER_GROUP);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $identities = app(CurrentMusicIdentityReader::class)->forReleases([self::RELEASE, self::RELEASE + 1]);
        $covers = app(AlbumCoverImages::class)->urlsFor($identities);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(3, $queries, 'the newest evidence, the completed decisions and the stored covers');
        $this->assertSame('https://musicbrainz.org/release-group/'.self::GROUP, $identities[self::RELEASE]->releaseGroupUrl());
        $this->assertSame(url('/covers/audio/'.self::OTHER_GROUP.'.jpg'), $covers[self::RELEASE], 'an edition that fell back shows the release group\'s file');
        $this->assertSame('', $identities[self::RELEASE + 1]->releaseGroupUrl(), 'an accepted recording names no album');
        $this->assertArrayNotHasKey(self::RELEASE + 1, $covers);
    }

    /**
     * A completed accepted album on revision 1 under version v1, then the new target the variant
     * names: the same evidence under version v2, or revision 2 under the same version.
     */
    private function acceptedThenNewTarget(string $variant): ReleaseAudioEvidence
    {
        $first = $this->evidence(self::RELEASE, 1);
        $this->complete($first, IdentificationStatus::AcceptedReleaseGroup, groupId: self::GROUP);
        if ($variant === 'version') {
            config(['music-identity.algorithm_version' => 'music-identity-v2']);

            return $first;
        }

        return $this->evidence(self::RELEASE, 2);
    }

    private function lease(ReleaseAudioEvidence $evidence, string $worker): ReleaseMusicIdentification
    {
        $lease = (new MusicIdentityLeaseManager)->acquire($evidence, $worker);
        $this->assertNotNull($lease, 'the lease manager hands out the target lease');

        return $lease;
    }

    /** @return array{link: string, cover: ?string} */
    private function album(string $groupId): array
    {
        return ['link' => 'https://musicbrainz.org/release-group/'.$groupId, 'cover' => url('/covers/audio/'.$groupId.'.jpg')];
    }

    /** @return array{link: string, cover: ?string} */
    private function display(int $releaseId): array
    {
        $identities = app(CurrentMusicIdentityReader::class)->forReleases([$releaseId]);

        return [
            'link' => ($identities[$releaseId] ?? null)?->releaseGroupUrl() ?? '',
            'cover' => app(AlbumCoverImages::class)->urlsFor($identities)[$releaseId] ?? null,
        ];
    }

    private function evidence(int $releaseId, int $revision): ReleaseAudioEvidence
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

    private function complete(
        ReleaseAudioEvidence $evidence,
        IdentificationStatus $status,
        ?string $releaseId = null,
        ?string $groupId = null,
        ?string $leaseToken = null,
    ): IdentificationStatus {
        $version = (string) config('music-identity.algorithm_version');
        $retryable = $status === IdentificationStatus::RetryableError;

        return (new IdentificationDecisionStore)->persist(
            releaseId: (int) $evidence->releases_id,
            evidence: new AudioEvidenceSet((int) $evidence->id, (string) $evidence->evidence_hash, null, null, null, null, []),
            decision: new IdentificationDecision(
                status: $status,
                score: $retryable ? 0 : 95,
                band: $retryable ? IdentificationBand::Unresolved : IdentificationBand::Strong,
                acceptedIdentity: $groupId === null ? null : new CandidateIdentity(releaseId: $releaseId, releaseGroupId: $groupId),
                reasons: [new DecisionReason('fixture', 'fixture')],
                candidates: [],
                runnerUpMargin: null,
                algorithmVersion: $version,
                resolverVersion: 'resolver-v1',
                normalizerVersion: 'normalizer-v1',
                scorerVersion: 'whole-release-v1',
                policyVersion: 'shadow-v1',
                operationalError: $retryable ? 'mirror unavailable' : null,
            ),
            nextAttemptAt: $retryable ? (new MusicIdentityRetryPolicy)->nextAttemptAt(0) : null,
            leaseToken: $leaseToken,
        )->state;
    }

    private function storedCover(string $kind, string $musicBrainzId, ?string $imageId = null): void
    {
        $imageId ??= $musicBrainzId;
        DB::table('music_cover_art_lookups')->insert([
            'kind' => $kind, 'musicbrainz_id' => $musicBrainzId, 'outcome' => 'stored', 'image_musicbrainz_id' => $imageId,
            'attempt_count' => 1, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! is_dir($this->covers.'/audio')) {
            mkdir($this->covers.'/audio', 0777, true);
        }
        file_put_contents($this->covers.'/audio/'.$imageId.'.jpg', 'jpg');
    }

    private function migration(string $pattern): Migration
    {
        $paths = glob(database_path('migrations/'.$pattern)) ?: [];
        $this->assertCount(1, $paths);

        /** @var Migration $migration */
        $migration = require $paths[0];

        return $migration;
    }
}
