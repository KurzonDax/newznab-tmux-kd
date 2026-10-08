<?php

declare(strict_types=1);

namespace Tests\Feature\MusicIdentity;

use App\Enums\NzbParseFailure;
use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseMusicIdentification;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AudioProcessing\AudioEvidenceSynthesizer;
use App\Services\MusicIdentity\AcousticFingerprintCandidates;
use App\Services\MusicIdentity\Contracts\CandidateGenerator;
use App\Services\MusicIdentity\CoverArt\AlbumCoverFetcher;
use App\Services\MusicIdentity\CoverArt\CoverArtPacer;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateHypothesis;
use App\Services\MusicIdentity\DTO\CandidateIdentity;
use App\Services\MusicIdentity\DTO\CandidateMetadata;
use App\Services\MusicIdentity\DTO\CandidatePool;
use App\Services\MusicIdentity\DTO\CandidateSignal;
use App\Services\MusicIdentity\Enums\CandidateSignalKind;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\Evidence\AudioEvidenceSetFactory;
use App\Services\MusicIdentity\Exceptions\MusicBrainzGatewayException;
use App\Services\MusicIdentity\MusicCandidateGenerator;
use App\Services\MusicIdentity\MusicIdentityConfiguration;
use App\Services\MusicIdentity\MusicIdentityResolver;
use App\Services\MusicIdentity\MusicIdentityRetryPolicy;
use App\Services\MusicIdentity\Persistence\IdentificationDecisionStore;
use App\Services\MusicIdentity\Persistence\MusicIdentityLeaseManager;
use App\Services\MusicIdentity\Persistence\MusicIdentitySynthesisLeaseManager;
use App\Services\MusicIdentity\Rename\MusicRenameProjection;
use App\Services\MusicIdentity\ResolveReleaseMusicIdentity;
use App\Services\Runners\PostProcessRunner;
use Illuminate\Database\Connectors\SQLiteConnector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ResolveReleaseMusicIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'music-identity.algorithm_version' => 'music-identity-v1',
            'music-identity.musicbrainz.endpoint_url' => 'https://musicbrainz.test/ws/2/',
            'music-identity.lease_seconds' => 300,
            'music-identity.retry.initial_seconds' => 60,
            'music-identity.retry.maximum_seconds' => 120,
        ]);
        Search::spy(); // decision writes re-sync the release search document, which these tests do not build
        DB::extend('sqlite', static function (array $config): SQLiteConnection {
            $pdo = (new SQLiteConnector)->connect($config);

            return new ChangedRowsSQLiteConnection(
                $pdo,
                (string) $config['database'],
                (string) ($config['prefix'] ?? ''),
                $config,
            );
        });
        DB::purge();
        DB::reconnect();

        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        DB::table('settings')->insert([
            ['name' => 'music_identity_enabled', 'value' => '1'],
            ['name' => 'music_identity_shadow', 'value' => '1'],
            ['name' => 'music_identity_workers', 'value' => '1'],
        ]);
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('forced_root_categories_id')->nullable();
        });
        DB::table('usenet_groups')->insert(['id' => 1, 'forced_root_categories_id' => null]);
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid')->unique();
            $table->char('leftguid', 1);
            $table->string('name');
            $table->string('searchname');
            $table->string('searchname_normalized')->default('');
            $table->string('display_name')->nullable();
            $table->string('fromname')->nullable();
            $table->unsignedInteger('groups_id');
            $table->unsignedInteger('categories_id');
            $table->unsignedBigInteger('size')->default(0);
            $table->integer('musicinfo_id')->nullable();
            foreach (['predb_id', 'is_trusted_name', 'isrenamed', 'iscategorized', 'proc_pp', 'videos_id', 'tv_episodes_id', 'gamesinfo_id'] as $column) {
                $table->integer($column)->default(0);
            }
            foreach (['movieinfo_id', 'consoleinfo_id', 'bookinfo_id', 'anidbid'] as $column) {
                $table->integer($column)->nullable();
            }
            $table->string('imdbid')->nullable();
            $table->string('name_source', 64)->nullable();
            $table->string('additional_pp_claim_token')->nullable();
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->timestamp('postdate')->nullable();
        });
        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('groups_id');
            $table->primary(['releases_id', 'groups_id']);
        });
        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('passworded')->default(false);
            $table->string('crc32', 8)->default('');
            $table->timestamps();
            $table->primary(['releases_id', 'name']);
        });

        $this->migration('*_create_release_audio_tags_table.php')->up();
        $this->migration('*_create_release_audio_evidence_tables.php')->up();
        $this->migration('*_create_release_music_identification_tables.php')->up();
        $this->migration('*_create_release_music_synthesis_attempts_table.php')->up();
        $this->migration('*_create_music_cover_art_lookups_table.php')->up();
        $this->migration('*_add_accepted_music_text_to_release_music_identifications.php')->up();
        $this->migration('*_create_release_music_renames_table.php')->up();
        config([
            'nntmux_settings.covers_path' => $this->makeTempDirectory('music-identity-covers'),
            'music-identity.cover_art.base_url' => 'https://caa.test',
            'music-identity.cover_art.min_interval_milliseconds' => 0,
        ]);
        Cache::flush();
        Log::spy();
    }

    #[Test]
    public function it_persists_a_shadow_decision_without_mutating_the_legacy_music_projection(): void
    {
        $release = $this->release(musicInfoId: 77);
        $evidence = $this->evidence($release);
        $resolverEvidence = (new AudioEvidenceSetFactory)->make($evidence);

        $this->assertSame('Artist - Album 2024 FLAC', $resolverEvidence->releaseTitle);
        $this->assertSame('Album', $resolverEvidence->albumTitle);
        $this->assertSame('Artist', $resolverEvidence->albumArtist);
        $this->assertSame(2024, $resolverEvidence->releaseYear);
        $this->assertSame(180_500, $resolverEvidence->trackEvidence[0]->durationMs);
        $this->assertSame(
            $resolverEvidence->albumProvenanceFamily,
            $resolverEvidence->trackEvidence[0]->provenanceFamily,
        );

        $identification = $this->worker(new EmptyCandidateGenerator)->resolveRelease($release, 'worker-a');

        $this->assertNotNull($identification);
        $this->assertSame(IdentificationStatus::Unresolved, $identification->state);
        $this->assertSame(1, $identification->attempt_count);
        $this->assertNotNull($identification->decided_at);
        $this->assertNull($identification->next_attempt_at);
        $this->assertSame(77, $release->fresh()->musicinfo_id);
        $this->assertNull($this->worker(new EmptyCandidateGenerator)->resolveRelease($release, 'worker-b'));
        $this->assertSame(1, ReleaseMusicIdentification::query()->count());
    }

    #[Test]
    public function it_lazily_synthesizes_back_catalog_evidence_before_resolution(): void
    {
        $release = $this->release();
        $this->mock(NzbContentParser::class, function (MockInterface $mock): void {
            $mock->shouldReceive('parseNzb')->once()->andReturn([
                'contents' => [],
                'error' => 'Stored NZB is malformed',
                'failure' => NzbParseFailure::Broken,
            ]);
        });

        $identification = $this->worker(new EmptyCandidateGenerator)->resolveRelease($release, 'worker-a');

        $this->assertNotNull($identification);
        $this->assertSame(IdentificationStatus::Unresolved, $identification->state);
        $this->assertDatabaseHas('release_audio_evidence', [
            'releases_id' => $release->id,
            'revision' => 1,
            'provenance' => 'synthesized',
        ]);
    }

    #[Test]
    public function synthesis_failures_are_recorded_and_back_off_before_becoming_eligible_again(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');
        $release = $this->release();
        $this->mock(NzbContentParser::class, function (MockInterface $mock): void {
            $mock->shouldReceive('parseNzb')->twice()->andReturn(
                [
                    'contents' => [],
                    'error' => 'NZB storage is unavailable',
                    'failure' => NzbParseFailure::StorageUnavailable,
                ],
                [
                    'contents' => [],
                    'error' => 'Stored NZB is malformed',
                    'failure' => NzbParseFailure::Broken,
                ],
            );
        });

        $this->assertNull($this->worker(new EmptyCandidateGenerator)->resolveRelease($release, 'worker-a'));
        $attempt = DB::table('release_music_synthesis_attempts')->where('releases_id', $release->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(1, (int) $attempt->attempt_count);
        $this->assertSame('NZB storage is unavailable', $attempt->last_operational_error);
        $this->assertSame(60, (int) now()->diffInSeconds($attempt->next_attempt_at, absolute: true));
        $this->assertSame(0, app(ResolveReleaseMusicIdentity::class)->eligibleCount());
        config(['music-identity.algorithm_version' => 'music-identity-v2']);
        $this->assertSame(1, app(ResolveReleaseMusicIdentity::class)->eligibleCount());
        config(['music-identity.algorithm_version' => 'music-identity-v1']);

        Carbon::setTestNow(Carbon::parse($attempt->next_attempt_at)->addSecond());
        $this->assertSame(1, app(ResolveReleaseMusicIdentity::class)->eligibleCount());

        $identification = $this->worker(new EmptyCandidateGenerator)->resolveRelease($release, 'worker-b');
        $this->assertNotNull($identification);
        $this->assertSame(IdentificationStatus::Unresolved, $identification->state);
        $this->assertDatabaseMissing('release_music_synthesis_attempts', ['releases_id' => $release->id]);
    }

    #[Test]
    public function leases_are_exclusive_renewable_and_recoverable_after_expiry(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');
        $release = $this->release();
        $evidence = $this->evidence($release);
        $leases = new MusicIdentityLeaseManager;

        $first = $leases->acquire($evidence, 'worker-a');

        $this->assertNotNull($first);
        $this->assertSame(IdentificationStatus::Pending, $first->state);
        $this->assertNull($leases->acquire($evidence, 'worker-b'));

        Carbon::setTestNow(now()->addMinutes(4));
        $this->assertTrue($leases->renew($first->id, 'worker-a'));
        $this->assertSame(now()->addSeconds(300)->getTimestamp(), $first->fresh()->lease_expires_at?->getTimestamp());

        Carbon::setTestNow(now()->addMinutes(6));
        $recovered = $leases->acquire($evidence, 'worker-b');
        $this->assertNotNull($recovered);
        $this->assertSame($first->id, $recovered->id);
        $this->assertSame('worker-b', $recovered->lease_token);
    }

    #[Test]
    public function leases_renew_in_the_same_frozen_second_when_new_or_reacquired(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');
        $release = $this->release();
        $evidence = $this->evidence($release);
        $leases = new MusicIdentityLeaseManager;

        $created = $leases->acquire($evidence, 'worker-a');

        $this->assertNotNull($created);
        $this->assertTrue($leases->renew($created->id, 'worker-a'));

        Carbon::setTestNow('2026-08-30 12:05:01');
        $reacquired = $leases->acquire($evidence, 'worker-b');

        $this->assertNotNull($reacquired);
        $this->assertSame($created->id, $reacquired->id);
        $this->assertTrue($leases->renew($reacquired->id, 'worker-b'));
    }

    #[Test]
    public function leases_cannot_be_renewed_with_the_wrong_token_or_after_expiry(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');
        $release = $this->release();
        $evidence = $this->evidence($release);
        $leases = new MusicIdentityLeaseManager;
        $lease = $leases->acquire($evidence, 'worker-a');

        $this->assertNotNull($lease);
        $this->assertFalse($leases->renew($lease->id, 'worker-b'));

        Carbon::setTestNow('2026-08-30 12:05:01');

        $this->assertFalse($leases->renew($lease->id, 'worker-a'));
    }

    #[Test]
    public function same_second_renewal_allows_resolution_to_proceed(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');
        $release = $this->release();
        $this->evidence($release);

        $identification = $this->worker(new EmptyCandidateGenerator)
            ->resolveRelease($release, 'worker-a');

        $this->assertNotNull($identification);
        $this->assertSame(IdentificationStatus::Unresolved, $identification->state);
        $this->assertSame(1, $identification->attempt_count);
        Log::shouldNotHaveReceived('notice');
    }

    #[Test]
    public function operational_errors_retry_with_bounded_exponential_backoff_then_can_become_no_match(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');
        $release = $this->release();
        $this->evidence($release);

        $first = $this->worker(new FailingCandidateGenerator('mirror unavailable'))
            ->resolveRelease($release, 'worker-a');
        $this->assertNotNull($first);
        $this->assertSame(IdentificationStatus::RetryableError, $first->state);
        $this->assertSame('mirror unavailable', $first->last_operational_error);
        $this->assertSame(60, (int) now()->diffInSeconds($first->next_attempt_at, absolute: true));
        $this->assertNull($first->decided_at);

        Carbon::setTestNow($first->next_attempt_at?->addSecond());
        $second = $this->worker(new FailingCandidateGenerator('mirror unavailable'))
            ->resolveRelease($release, 'worker-b');
        $this->assertNotNull($second);
        $this->assertSame(2, $second->attempt_count);
        $this->assertSame(120, (int) now()->diffInSeconds($second->next_attempt_at, absolute: true));

        Carbon::setTestNow($second->next_attempt_at?->addSecond());
        $third = $this->worker(new FailingCandidateGenerator('mirror unavailable'))
            ->resolveRelease($release, 'worker-c');
        $this->assertNotNull($third);
        $this->assertSame(3, $third->attempt_count);
        $this->assertSame(120, (int) now()->diffInSeconds($third->next_attempt_at, absolute: true));

        Carbon::setTestNow($third->next_attempt_at?->addSecond());
        $resolved = $this->worker(new EmptyCandidateGenerator)->resolveRelease($release, 'worker-d');
        $this->assertNotNull($resolved);
        $this->assertSame($first->id, $resolved->id);
        $this->assertSame(IdentificationStatus::Unresolved, $resolved->state);
        $this->assertSame(4, $resolved->attempt_count);
        $this->assertNull($resolved->last_operational_error);
        $this->assertNull($resolved->next_attempt_at);
        $this->assertNotNull($resolved->decided_at);
    }

    #[Test]
    public function a_release_whose_only_identifier_is_a_cddb_disc_id_reaches_a_decision(): void
    {
        config([
            'music-identity.musicbrainz.user_agent_contact' => '',
            'music-identity.musicbrainz.retry.attempts' => 1,
            'music-identity.musicbrainz.retry.backoff_milliseconds' => 0,
        ]);
        Http::fake(['*' => Http::response([
            'count' => 0,
            'offset' => 0,
            'recordings' => [],
            'releases' => [],
        ])]);
        $release = $this->release();
        $evidence = $this->evidence($release);
        $evidence->tracks()->firstOrFail()->update(['disc_id_like' => '9a0bc70c']);

        $identification = $this->worker(app(MusicCandidateGenerator::class))->resolveRelease($release, 'worker-a');

        $this->assertNotNull($identification);
        $this->assertSame(IdentificationStatus::Unresolved, $identification->state);
        $this->assertNull($identification->last_operational_error);
        $this->assertNotNull($identification->decided_at);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'discid'));
    }

    #[Test]
    public function an_unresolved_fingerprinted_release_is_looked_up_and_its_decision_stamps_the_lookup(): void
    {
        config([
            'music-identity.acoustid.client_key' => 'synthetic-client-key',
            'music-identity.acoustid.lookup_url' => 'https://acoustid.test/v2/lookup',
        ]);
        $this->migration('*_add_acoustic_fingerprints_to_release_audio_evidence_tracks.php')->up();
        Http::fake(['https://acoustid.test/*' => Http::response(['status' => 'ok', 'results' => []])]);
        $release = $this->release();
        $evidence = $this->evidence($release);
        $evidence->tracks()->firstOrFail()->update([
            'fingerprint' => 'AQADtSyntheticFeatureFingerprint',
            'fingerprint_hash' => hash('sha256', 'AQADtSyntheticFeatureFingerprint'),
            'fingerprint_algorithm' => 2,
            'fingerprint_generator_version' => 'synthetic-generator-v1',
        ]);

        $track = (new AudioEvidenceSetFactory)->make($evidence->fresh())->trackEvidence[0];
        $this->assertSame('AQADtSyntheticFeatureFingerprint', $track->fingerprint);
        $this->assertSame(hash('sha256', 'AQADtSyntheticFeatureFingerprint'), $track->fingerprintHash);
        $this->assertSame(2, $track->fingerprintAlgorithm);
        $this->assertSame('synthetic-generator-v1', $track->fingerprintGeneratorVersion);

        $identification = $this->worker(new EmptyCandidateGenerator, app(AcousticFingerprintCandidates::class))
            ->resolveRelease($release, 'worker-a');

        $this->assertNotNull($identification);
        $this->assertSame(IdentificationStatus::Unresolved, $identification->state);
        $this->assertSame(now()->toDateTimeString(), $identification->fresh()?->acoustid_looked_up_at?->toDateTimeString());
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request['fingerprint'] === 'AQADtSyntheticFeatureFingerprint'
            && (int) $request['duration'] === 181);
    }

    #[Test]
    public function an_accepted_album_decision_fetches_its_cover_after_it_is_persisted(): void
    {
        Http::fake(['https://caa.test/release/'.AcceptingCandidateGenerator::RELEASE_ID.'/front-500' => Http::response($this->image(), 200)]);
        $release = $this->release();
        $this->albumEvidence($release);

        $identification = $this->worker(new AcceptingCandidateGenerator)->resolveRelease($release, 'worker-a');

        $this->assertNotNull($identification);
        $this->assertSame(IdentificationStatus::AcceptedEdition, $identification->state);
        $this->assertDatabaseHas('music_cover_art_lookups', ['kind' => 'release', 'musicbrainz_id' => AcceptingCandidateGenerator::RELEASE_ID, 'outcome' => 'stored']);
        $this->assertNotNull(getImageAssetUrl('audio', AcceptingCandidateGenerator::RELEASE_ID));
    }

    #[Test]
    public function an_accepted_album_decision_renames_the_release_once_it_is_persisted(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        $release = $this->release();
        $this->albumEvidence($release);

        $identification = $this->worker(new AcceptingCandidateGenerator)->resolveRelease($release, 'worker-a');

        $this->assertNotNull($identification);
        $this->assertSame('Example Artist - Example Album (2020) FLAC', DB::table('releases')->where('id', $release->id)->value('searchname'));
        $this->assertDatabaseHas('release_music_renames', ['release_music_identification_id' => $identification->id, 'outcome' => 'applied']);
    }

    #[Test]
    public function a_cover_failure_or_a_pacing_timeout_leaves_the_decision_unchanged(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        $release = $this->release();
        $this->albumEvidence($release);

        $failed = $this->worker(new AcceptingCandidateGenerator)->resolveRelease($release, 'worker-a');

        $this->assertNotNull($failed);
        $this->assertSame(IdentificationStatus::AcceptedEdition, $failed->state);
        $this->assertDatabaseHas('music_cover_art_lookups', ['musicbrainz_id' => AcceptingCandidateGenerator::RELEASE_ID, 'outcome' => 'failed']);
        $stored = ReleaseMusicIdentification::query()->findOrFail($failed->id);
        $this->assertSame([IdentificationStatus::AcceptedEdition, 1, $failed->decided_at?->toDateTimeString(), null, null],
            [$stored->state, $stored->attempt_count, $stored->decided_at?->toDateTimeString(), $stored->next_attempt_at, $stored->last_operational_error]);

        DB::table('music_cover_art_lookups')->delete();
        DB::table('release_music_identifications')->delete();
        config(['music-identity.cover_art.lock_wait_seconds' => 0]);
        $held = Cache::lock(CoverArtPacer::LOCK, 60);
        $this->assertTrue($held->get());
        $deferred = $this->worker(new AcceptingCandidateGenerator)->resolveRelease($release, 'worker-b');
        $held->release();

        $this->assertNotNull($deferred);
        $this->assertSame(IdentificationStatus::AcceptedEdition, $deferred->state);
        $this->assertNotNull($deferred->decided_at);
        $this->assertSame(0, DB::table('music_cover_art_lookups')->count(), 'deferred: no outcome');
    }

    #[Test]
    public function the_cover_catch_up_follows_the_shared_current_decision_rule(): void
    {
        Http::fake(['https://caa.test/release-group/*' => Http::response($this->image(), 200)]);
        $release = $this->release();
        $evidence = $this->evidence($release);
        $current = $this->acceptedGroup($release, $evidence, '11111111-1111-4111-8111-111111111111', 'music-identity-v1');
        $this->acceptedGroup($release, $evidence, '22222222-2222-4222-8222-222222222222', 'music-identity-v0');

        $this->assertSame(1, $this->worker(new EmptyCandidateGenerator)->catchUpCovers());

        $this->assertDatabaseHas('music_cover_art_lookups', ['kind' => 'release-group', 'musicbrainz_id' => '11111111-1111-4111-8111-111111111111', 'outcome' => 'stored']);
        $this->assertSame(1, DB::table('music_cover_art_lookups')->count(), 'the configured version\'s completed decision is current; the other is not looked up');
        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, ReleaseMusicIdentification::query()->findOrFail($current)->state);
        $this->assertSame(0, $this->worker(new EmptyCandidateGenerator)->catchUpCovers(), 'a stored outcome is not looked up again');
        Http::assertSentCount(1);

        // After a version bump with no new row the newest completed decision stays current, as on the pages.
        config(['music-identity.algorithm_version' => 'music-identity-v2']);
        $this->assertSame(1, $this->worker(new EmptyCandidateGenerator)->catchUpCovers());
        $this->assertDatabaseHas('music_cover_art_lookups', ['kind' => 'release-group', 'musicbrainz_id' => '22222222-2222-4222-8222-222222222222', 'outcome' => 'stored']);
    }

    #[Test]
    public function the_music_pass_catches_up_renames_and_covers_even_when_no_release_awaits_identification(): void
    {
        Http::fake(['https://caa.test/release-group/*' => Http::response($this->image(), 200)]);
        $release = $this->release();
        $evidence = $this->evidence($release);
        $this->acceptedGroup($release, $evidence, '11111111-1111-4111-8111-111111111111', 'music-identity-v1');
        $this->app->instance(ResolveReleaseMusicIdentity::class, $this->worker(new EmptyCandidateGenerator));
        $runner = new class extends PostProcessRunner
        {
            /** @var list<string> */
            public array $commands = [];

            protected function runStreamingCommands(array $commands, int $maxProcesses, string $desc, ?callable $onComplete = null): void
            {
                array_push($this->commands, ...$commands);
            }

            protected function headerNone(): void {}
        };
        $this->assertSame([], app(ResolveReleaseMusicIdentity::class)->eligibleBuckets());

        $runner->processMusic();

        $this->assertSame([], $runner->commands);
        $this->assertDatabaseHas('music_cover_art_lookups', ['musicbrainz_id' => '11111111-1111-4111-8111-111111111111', 'outcome' => 'stored']);
        // The same pass gave the decision its rename record; it stored no candidate to rename from.
        $this->assertDatabaseHas('release_music_renames', ['releases_id' => $release->id, 'outcome' => 'declined', 'reason' => 'no_accepted_evaluation']);
    }

    #[Test]
    public function the_compatibility_processor_delegates_to_the_evidence_worker_not_music_service(): void
    {
        $source = file_get_contents(app_path('Services/MusicProcessor.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('ResolveReleaseMusicIdentity', $source);
        $this->assertStringNotContainsString('new MusicService', $source);
    }

    #[Test]
    public function the_amazon_pane_runs_music_with_its_dedicated_worker_limit(): void
    {
        $release = $this->release();
        $this->evidence($release);
        DB::table('settings')->where('name', 'music_identity_workers')->update(['value' => '3']);
        config(['nntmux.stream_fork_output' => true]);
        $runner = new class extends PostProcessRunner
        {
            /** @var list<string> */
            public array $commands = [];

            /** @var list<int> */
            public array $parallelism = [];

            protected function runStreamingCommands(
                array $commands,
                int $maxProcesses,
                string $desc,
                ?callable $onComplete = null,
            ): void {
                array_push($this->commands, ...$commands);
                $this->parallelism[] = $maxProcesses;
            }

            protected function headerNone(): void {}
        };

        $runner->processAmazon();

        $this->assertSame([[PHP_BINARY, 'artisan', 'postprocess:guid', 'music', 'a']], $runner->commands);
        $this->assertSame([3], $runner->parallelism);
    }

    #[Test]
    public function the_guid_worker_accepts_mus_as_a_compatibility_alias(): void
    {
        $release = $this->release();
        $this->evidence($release);
        $this->app->instance(CandidateGenerator::class, new EmptyCandidateGenerator);

        $status = Artisan::call('postprocess:guid', [
            'type' => 'mus',
            'guid' => 'a',
        ]);

        $this->assertSame(0, $status);
        $this->assertDatabaseHas('release_music_identifications', [
            'releases_id' => $release->id,
            'state' => IdentificationStatus::Unresolved->value,
        ]);
    }

    private function worker(
        CandidateGenerator $candidateGenerator,
        ?AcousticFingerprintCandidates $fingerprintCandidates = null,
    ): ResolveReleaseMusicIdentity {
        $retryPolicy = new MusicIdentityRetryPolicy;

        return new ResolveReleaseMusicIdentity(
            configuration: new MusicIdentityConfiguration,
            synthesizer: app(AudioEvidenceSynthesizer::class),
            evidenceFactory: new AudioEvidenceSetFactory,
            resolver: new MusicIdentityResolver(
                candidateGenerator: $candidateGenerator,
                algorithmVersion: (string) config('music-identity.algorithm_version'),
                fingerprintCandidates: $fingerprintCandidates,
            ),
            leases: new MusicIdentityLeaseManager,
            synthesisLeases: new MusicIdentitySynthesisLeaseManager($retryPolicy),
            retryPolicy: $retryPolicy,
            decisions: new IdentificationDecisionStore,
            covers: new AlbumCoverFetcher(new CoverArtPacer),
            renames: app(MusicRenameProjection::class),
        );
    }

    private function release(?int $musicInfoId = null): Release
    {
        $releaseId = DB::table('releases')->insertGetId([
            'guid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'leftguid' => 'a',
            'name' => 'Artist - Album',
            'searchname' => 'Artist - Album 2024 FLAC',
            'groups_id' => 1,
            'categories_id' => Category::MUSIC_LOSSLESS,
            'musicinfo_id' => $musicInfoId,
            'postdate' => now(),
        ]);

        return Release::query()->findOrFail($releaseId);
    }

    private function evidence(Release $release): ReleaseAudioEvidence
    {
        $evidence = ReleaseAudioEvidence::query()->create([
            'releases_id' => $release->id,
            'revision' => 1,
            'evidence_hash' => str_repeat('a', 64),
            'schema_version' => 1,
            'provenance' => 'captured',
            'release_snapshot' => [
                'name' => $release->name,
                'searchname' => $release->searchname,
            ],
            'archive_manifest_complete' => true,
            'nzb_manifest' => [],
            'archive_manifest' => [],
            'sidecar_manifest' => [],
            'captured_at' => now(),
        ]);
        $evidence->tracks()->create([
            'source_kind' => 'archive',
            'source_ordinal' => 1,
            'raw_filename' => '01 - Track.flac',
            'track_number' => 1,
            'album' => 'Album',
            'album_artist' => 'Artist',
            'title' => 'Track',
            'recorded_date' => '2024',
            'whole_duration_seconds' => 180.5,
            'whole_duration_reliable' => true,
        ]);

        return $evidence;
    }

    private function albumEvidence(Release $release): ReleaseAudioEvidence
    {
        $evidence = ReleaseAudioEvidence::query()->create([
            'releases_id' => $release->id,
            'revision' => 1,
            'evidence_hash' => str_repeat('d', 64),
            'schema_version' => 1,
            'provenance' => 'captured',
            'release_snapshot' => ['name' => 'Example Artist - Example Album', 'searchname' => 'Example Artist - Example Album 2020 FLAC'],
            'archive_manifest_complete' => true,
            'nzb_manifest' => [],
            'archive_manifest' => [],
            'sidecar_manifest' => [],
            'captured_at' => now(),
        ]);
        foreach (['First Light', 'Last Light'] as $index => $title) {
            $evidence->tracks()->create([
                'source_kind' => 'archive',
                'source_ordinal' => $index + 1,
                'raw_filename' => sprintf('%02d - %s.flac', $index + 1, $title),
                'track_number' => $index + 1,
                'album' => 'Example Album',
                'album_artist' => 'Example Artist',
                'performer' => 'Example Artist',
                'title' => $title,
                'recorded_date' => '2020',
                'whole_duration_seconds' => $index === 0 ? 180 : 210,
                'whole_duration_reliable' => true,
                'musicbrainz_release_id' => AcceptingCandidateGenerator::RELEASE_ID,
            ]);
        }

        return $evidence;
    }

    private function acceptedGroup(Release $release, ReleaseAudioEvidence $evidence, string $group, string $version): int
    {
        return DB::table('release_music_identifications')->insertGetId([
            'releases_id' => $release->id, 'release_audio_evidence_id' => $evidence->id, 'evidence_hash' => $evidence->evidence_hash,
            'state' => IdentificationStatus::AcceptedReleaseGroup->value, 'band' => 'strong', 'musicbrainz_release_group_id' => $group,
            'reasons' => '[]', 'feature_contributions' => '[]', 'algorithm_version' => $version, 'resolver_version' => 'r1',
            'normalizer_version' => 'n1', 'scorer_version' => 's1', 'policy_version' => 'p1', 'decided_at' => now(),
        ]);
    }

    private function image(): string
    {
        $image = imagecreatetruecolor(40, 40);
        $this->assertNotFalse($image);
        ob_start();
        imagejpeg($image, null, 82);

        return (string) ob_get_clean();
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

final readonly class EmptyCandidateGenerator implements CandidateGenerator
{
    public function generate(AudioEvidenceSet $evidence): CandidatePool
    {
        return new CandidatePool([]);
    }
}

/** Accepts the edition the album evidence's embedded release id names (MusicIdentityResolverTest's fixture). */
final readonly class AcceptingCandidateGenerator implements CandidateGenerator
{
    public const string RELEASE_ID = '44444444-4444-4444-8444-444444444444';

    public const string RELEASE_GROUP_ID = '66666666-6666-4666-8666-666666666666';

    public function generate(AudioEvidenceSet $evidence): CandidatePool
    {
        $releaseTracks = [];
        foreach (['First Light', 'Last Light'] as $index => $title) {
            $length = $index === 0 ? 180_000 : 210_000;
            $releaseTracks[] = [
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
        $identity = new CandidateIdentity(releaseId: self::RELEASE_ID, releaseGroupId: self::RELEASE_GROUP_ID);

        return new CandidatePool([new CandidateHypothesis(
            $identity,
            new CandidateMetadata([], [[
                'releaseId' => self::RELEASE_ID, 'title' => 'Example Album', 'artistCredit' => 'Example Artist', 'releaseGroupId' => self::RELEASE_GROUP_ID,
                'status' => 'Official', 'date' => '2020-01-01', 'country' => 'US', 'barcode' => null, 'labels' => [], 'aliases' => [],
                'media' => [['position' => 1, 'title' => null, 'format' => 'CD', 'releaseTrackCount' => 2, 'discIds' => [], 'releaseTracks' => $releaseTracks]],
            ]], [[
                'releaseGroupId' => self::RELEASE_GROUP_ID, 'title' => 'Example Album', 'artistCredit' => 'Example Artist', 'primaryType' => 'Album',
                'secondaryTypes' => [], 'firstReleaseDate' => '2020-01-01', 'aliases' => [],
            ]]),
            [new CandidateSignal(CandidateSignalKind::EmbeddedReleaseId, self::RELEASE_ID, $evidence->trackEvidence[0]->provenanceFamily ?? 'tag', true, $identity)],
        )]);
    }
}

final readonly class FailingCandidateGenerator implements CandidateGenerator
{
    public function __construct(private string $message) {}

    public function generate(AudioEvidenceSet $evidence): CandidatePool
    {
        throw new MusicBrainzGatewayException($this->message);
    }
}

final class ChangedRowsSQLiteConnection extends SQLiteConnection
{
    /**
     * Simulate MariaDB reporting zero changed rows for a same-value lease renewal.
     *
     * @param  array<int, mixed>  $bindings
     */
    public function affectingStatement($query, $bindings = []): int
    {
        $affectedRows = parent::affectingStatement($query, $bindings);
        $normalizedQuery = strtolower((string) $query);

        if (str_starts_with($normalizedQuery, 'update "release_music_identifications" set ')
            && str_contains($normalizedQuery, '"lease_expires_at" = ?')
            && substr_count(strstr($normalizedQuery, ' where ', true) ?: '', ' = ?') === 2) {
            return 0;
        }

        return $affectedRows;
    }
}
