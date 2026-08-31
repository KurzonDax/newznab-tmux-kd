<?php

declare(strict_types=1);

namespace Tests\Feature\MusicIdentity;

use App\Enums\NzbParseFailure;
use App\Models\Category;
use App\Models\Release;
use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseMusicIdentification;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AudioProcessing\AudioEvidenceSynthesizer;
use App\Services\MusicIdentity\Contracts\CandidateGenerator;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidatePool;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\Evidence\AudioEvidenceSetFactory;
use App\Services\MusicIdentity\Exceptions\MusicBrainzGatewayException;
use App\Services\MusicIdentity\MusicIdentityConfiguration;
use App\Services\MusicIdentity\MusicIdentityResolver;
use App\Services\MusicIdentity\MusicIdentityRetryPolicy;
use App\Services\MusicIdentity\Persistence\IdentificationDecisionStore;
use App\Services\MusicIdentity\Persistence\MusicIdentityLeaseManager;
use App\Services\MusicIdentity\Persistence\MusicIdentitySynthesisLeaseManager;
use App\Services\MusicIdentity\ResolveReleaseMusicIdentity;
use App\Services\Runners\PostProcessRunner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
            $table->string('guid');
            $table->char('leftguid', 1);
            $table->string('name');
            $table->string('searchname');
            $table->unsignedInteger('groups_id');
            $table->unsignedInteger('categories_id');
            $table->unsignedBigInteger('size')->default(0);
            $table->integer('musicinfo_id')->nullable();
            $table->string('additional_pp_claim_token')->nullable();
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->timestamp('postdate')->nullable();
        });
        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('groups_id');
        });
        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('passworded')->default(false);
            $table->string('crc32', 8)->default('');
            $table->timestamps();
        });

        $this->migration('*_create_release_audio_tags_table.php')->up();
        $this->migration('*_create_release_audio_evidence_tables.php')->up();
        $this->migration('*_create_release_music_identification_tables.php')->up();
        $this->migration('*_create_release_music_synthesis_attempts_table.php')->up();
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

        $this->assertSame([PHP_BINARY.' artisan postprocess:guid music a'], $runner->commands);
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

    private function worker(CandidateGenerator $candidateGenerator): ResolveReleaseMusicIdentity
    {
        $retryPolicy = new MusicIdentityRetryPolicy;

        return new ResolveReleaseMusicIdentity(
            configuration: new MusicIdentityConfiguration,
            synthesizer: app(AudioEvidenceSynthesizer::class),
            evidenceFactory: new AudioEvidenceSetFactory,
            resolver: new MusicIdentityResolver(candidateGenerator: $candidateGenerator),
            leases: new MusicIdentityLeaseManager,
            synthesisLeases: new MusicIdentitySynthesisLeaseManager($retryPolicy),
            retryPolicy: $retryPolicy,
            decisions: new IdentificationDecisionStore,
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

final readonly class FailingCandidateGenerator implements CandidateGenerator
{
    public function __construct(private string $message) {}

    public function generate(AudioEvidenceSet $evidence): CandidatePool
    {
        throw new MusicBrainzGatewayException($this->message);
    }
}
