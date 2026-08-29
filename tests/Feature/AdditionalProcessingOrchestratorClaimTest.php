<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\AdditionalProcessingOrchestrator;
use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\DTO\ReleaseProcessingResult;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseProcessor;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\NameFixing\NameFixingService;
use App\Services\NfoService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\TempWorkspaceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

class AdditionalProcessingOrchestratorClaimTest extends TestCase
{
    use CreatesProcessingConfiguration;
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '0', 'releaseprocessingtimeout' => '120'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        config([
            'nntmux_settings.check_passworded_rars' => true,
            'nntmux_settings.unrar_path' => '/usr/bin/unrar',
        ]);

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_handled_release_exception_is_counted_and_left_pending_below_the_cap(): void
    {
        Log::spy();

        DB::table('categories')->insert(['id' => 1]);
        DB::table('releases')->insert($this->releaseRow());

        $processor = new FailingAdditionalReleaseProcessor;
        $tempWorkspace = new RecordingTempWorkspaceService;
        $output = new RecordingConsoleOutputService;
        $releaseManager = Mockery::mock(ReleaseFileManager::class);
        $releaseManager->shouldReceive('handleReleaseException')
            ->once()
            ->with(Mockery::type(Release::class), 3, Mockery::type('string'))
            ->andReturnUsing(static function (Release $release): bool {
                Release::query()->whereKey($release->id)->update([
                    'pp_timeout_count' => 1,
                    'additional_pp_claimed_at' => null,
                    'additional_pp_claim_token' => null,
                ]);

                return false;
            });

        $orchestrator = new AdditionalProcessingOrchestrator(
            $this->makeConfig(['queryLimit' => 25, 'minSizeBytes' => 0, 'maxSizeBytes' => 107374182400]),
            $processor,
            $tempWorkspace,
            $output,
            $releaseManager,
        );

        $result = $orchestrator->start('', 'a');

        $this->assertSame(1, $processor->processCalls);
        $this->assertSame([1], $result->claimedIds);
        $this->assertSame(0, $result->successfulCount());
        $this->assertSame(1, $result->unsuccessfulCount());
        $this->assertTrue($result->hasOutcome(ProcessingOutcome::Failed));
        $this->assertGreaterThan(0.0, $result->elapsedSeconds);
        $this->assertGreaterThan(0.0, $result->averageReleaseSeconds());
        $this->assertGreaterThan(0.0, $result->releasesPerSecond());
        $this->assertGreaterThan(0, $result->peakMemoryBytes);
        $this->assertSame(1, $tempWorkspace->ensureMainTempPathCalls);
        $this->assertSame(1, $tempWorkspace->clearDirectoryCalls);
        $this->assertSame(1, $output->echoDescriptionCalls);
        $this->assertSame(1, $output->endOutputCalls);
        $this->assertSame(1, (int) Release::query()->whereKey(1)->value('pp_timeout_count'));
        $this->assertSame(-1, (int) Release::query()->whereKey(1)->value('haspreview'));
        $this->assertNull(Release::query()->where('id', 1)->value('additional_pp_claimed_at'));
        $this->assertNull(Release::query()->where('id', 1)->value('additional_pp_claim_token'));

        Log::shouldHaveReceived('info')
            ->once()
            ->with(
                'Additional postprocessing run finished',
                Mockery::on(static fn (array $context): bool => $context['picked'] === 1
                    && $context['processed'] === 0
                    && $context['failed'] === 1
                    && $context['outcomes'] === ['failed' => 1]
                    && array_key_exists('artifacts_created', $context)
                    && array_key_exists('artifact_yield_percent', $context)
                    && array_key_exists('release_files_added', $context)
                    && array_key_exists('download_requests', $context)
                    && array_key_exists('nntp_requests', $context)
                    && array_key_exists('download_cache_hits', $context)
                    && array_key_exists('crc_failures', $context)
                    && array_key_exists('database_statements', $context)
                    && array_key_exists('database_milliseconds', $context)
                    && array_key_exists('search_sync_requests', $context)
                    && array_key_exists('search_sync_executions', $context)
                    && array_key_exists('duplicate_message_ids', $context)
                    && array_key_exists('unsupported_reasons', $context)
                    && array_key_exists('sniffed_candidates', $context)
                    && array_key_exists('payload_classifications', $context)
                    && array_key_exists('mp4-tail-fetched', $context)
                    && array_key_exists('mp4-moov-found', $context)
                    && array_key_exists('mp4-moov-missing', $context)
                    && array_key_exists('mp4-tail-bytes', $context)
                    && array_key_exists('releases_per_second', $context)
                    && array_key_exists('average_release_seconds', $context)
                    && array_key_exists('stage_seconds', $context)
                    && array_key_exists('peak_memory_bytes', $context)),
            );
    }

    public function test_process_timeout_exception_is_counted_and_settled_at_the_cap(): void
    {
        DB::table('categories')->insert(['id' => 1]);
        DB::table('releases')->insert($this->releaseRow());
        $releaseManager = new ReleaseFileManager(
            $this->makeConfig(),
            Mockery::mock(ReleaseImageService::class),
            Mockery::mock(NfoService::class),
            Mockery::mock(NzbService::class),
            Mockery::mock(NameFixingService::class),
            searchSyncCoordinator: new ReleaseSearchSyncCoordinator(
                new PersistenceMetricsCollector,
                static function (int $releaseId): void {},
            ),
        );
        $timedOutProcess = new Process(['php', '-v']);
        $timedOutProcess->setTimeout(1);
        $processor = new TimedOutAdditionalReleaseProcessor(
            new ProcessTimedOutException($timedOutProcess, ProcessTimedOutException::TYPE_GENERAL),
        );
        $orchestrator = new AdditionalProcessingOrchestrator(
            $this->makeConfig([
                'queryLimit' => 25,
                'minSizeBytes' => 0,
                'maxSizeBytes' => 107374182400,
                'maxPpTimeoutCount' => 1,
            ]),
            $processor,
            new RecordingTempWorkspaceService,
            new RecordingConsoleOutputService,
            $releaseManager,
        );

        $result = $orchestrator->start('', 'a');
        $release = Release::query()->findOrFail(1);

        $this->assertSame(1, $processor->processCalls);
        $this->assertTrue($result->hasOutcome(ProcessingOutcome::Failed));
        $this->assertSame(1, (int) $release->pp_timeout_count);
        $this->assertSame(0, (int) $release->haspreview);
        $this->assertSame(0, (int) $release->passwordstatus);
        $this->assertFalse(AdditionalCandidateQuery::hasAnyCandidate());
    }

    public function test_storage_unavailability_aborts_the_batch_and_releases_remaining_claims(): void
    {
        Log::spy();

        DB::table('categories')->insert(['id' => 1]);
        DB::table('releases')->insert([
            $this->releaseRow(),
            $this->releaseRow(2),
        ]);

        $processor = new StorageUnavailableAdditionalReleaseProcessor;
        $orchestrator = new AdditionalProcessingOrchestrator(
            $this->makeConfig(['queryLimit' => 25, 'minSizeBytes' => 0, 'maxSizeBytes' => 107374182400]),
            $processor,
            new RecordingTempWorkspaceService,
            new RecordingConsoleOutputService,
            Mockery::mock(ReleaseFileManager::class),
        );

        $result = $orchestrator->start('', 'a');

        $this->assertSame([1, 2], $result->claimedIds);
        $this->assertSame(1, $result->attemptedCount());
        $this->assertTrue($result->hasOutcome(ProcessingOutcome::StorageUnavailable));
        $this->assertSame(1, $processor->processCalls);
        $this->assertSame([0, 0], DB::table('releases')->orderBy('id')->pluck('pp_timeout_count')->all());
        $this->assertSame([null, null], DB::table('releases')->orderBy('id')->pluck('additional_pp_claimed_at')->all());
        $this->assertSame([null, null], DB::table('releases')->orderBy('id')->pluck('additional_pp_claim_token')->all());

        Log::shouldHaveReceived('critical')
            ->once()
            ->with('Additional postprocessing aborted because an environmental dependency is unavailable', Mockery::type('array'));
    }

    public function test_secondary_exception_handling_failure_releases_the_entire_claimed_batch(): void
    {
        DB::table('categories')->insert(['id' => 1]);
        DB::table('releases')->insert([
            $this->releaseRow(),
            $this->releaseRow(2),
        ]);

        $releaseManager = Mockery::mock(ReleaseFileManager::class);
        $releaseManager->shouldReceive('handleReleaseException')
            ->once()
            ->andThrow(new \RuntimeException('exception accounting unavailable'));
        $orchestrator = new AdditionalProcessingOrchestrator(
            $this->makeConfig(['queryLimit' => 25, 'minSizeBytes' => 0, 'maxSizeBytes' => 107374182400]),
            new FailingAdditionalReleaseProcessor,
            new RecordingTempWorkspaceService,
            new RecordingConsoleOutputService,
            $releaseManager,
        );

        try {
            $orchestrator->start('', 'a');
            $this->fail('Expected exception accounting failure to escape.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('exception accounting unavailable', $exception->getMessage());
        }

        $this->assertSame([null, null], DB::table('releases')->orderBy('id')->pluck('additional_pp_claimed_at')->all());
        $this->assertSame([null, null], DB::table('releases')->orderBy('id')->pluck('additional_pp_claim_token')->all());
    }

    public function test_temp_setup_failure_does_not_claim_releases(): void
    {
        DB::table('categories')->insert(['id' => 1]);
        DB::table('releases')->insert($this->releaseRow());

        $processor = new FailingAdditionalReleaseProcessor;
        $tempWorkspace = new FailingTempWorkspaceService;
        $output = new RecordingConsoleOutputService;

        $orchestrator = new AdditionalProcessingOrchestrator(
            $this->makeConfig(['queryLimit' => 25, 'minSizeBytes' => 0, 'maxSizeBytes' => 107374182400]),
            $processor,
            $tempWorkspace,
            $output,
            Mockery::mock(ReleaseFileManager::class),
        );

        $result = $orchestrator->start('', 'a');

        $this->assertSame(0, $processor->processCalls);
        $this->assertSame(0, $result->claimedCount());
        $this->assertStringContainsString('not writable', $result->setupFailure);
        $this->assertSame(1, $tempWorkspace->ensureMainTempPathCalls);
        $this->assertSame(0, $output->echoDescriptionCalls);
        $this->assertSame(0, $output->endOutputCalls);
        $this->assertStringContainsString('Additional post-processing skipped', $output->warnings[0] ?? '');
        $this->assertNull(Release::query()->where('id', 1)->value('additional_pp_claimed_at'));
        $this->assertNull(Release::query()->where('id', 1)->value('additional_pp_claim_token'));
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseRow(int $id = 1): array
    {
        return [
            'id' => $id,
            'guid' => 'a-guid-'.$id,
            'leftguid' => 'a',
            'name' => 'Example '.$id,
            'searchname' => 'Example '.$id,
            'size' => 2 * 1048576,
            'groups_id' => 1,
            'nfostatus' => -1,
            'fromname' => 'poster@example.test',
            'completion' => 100,
            'categories_id' => 1,
            'predb_id' => 0,
            'pp_timeout_count' => 0,
            'passwordstatus' => -1,
            'haspreview' => -1,
            'nzbstatus' => 1,
            'postdate' => '2026-07-12 10:00:00',
            'additional_pp_claimed_at' => null,
            'additional_pp_claim_token' => null,
        ];
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        DB::table('settings')->upsert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'releaseprocessingtimeout', 'value' => '120'],
        ], ['name'], ['value']);

        Schema::dropIfExists('releases_groups');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('categories');

        Schema::create('categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });

        // The candidate queries partition the pending set by audio routing, which
        // reaches into usenet_groups for the forced-root override.
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->default('');
            $table->unsignedInteger('forced_root_categories_id')->nullable();
        });

        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('guid');
            $table->char('leftguid', 1);
            $table->string('name')->default('');
            $table->string('searchname')->default('');
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('groups_id')->default(0);
            $table->integer('nfostatus')->default(0);
            $table->string('fromname')->nullable();
            $table->float('completion')->default(0);
            $table->unsignedInteger('categories_id');
            $table->unsignedInteger('predb_id')->default(0);
            $table->integer('pp_timeout_count')->default(0);
            $table->integer('passwordstatus');
            $table->integer('haspreview');
            $table->integer('nzbstatus');
            $table->dateTime('postdate')->nullable();
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->string('additional_pp_claim_token', 64)->nullable();
        });

        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('groups_id');
        });
    }
}

class FailingAdditionalReleaseProcessor extends ReleaseProcessor
{
    public int $processCalls = 0;

    public function __construct() {}

    public function process(ReleaseProcessingContext $context, string $mainTmpPath): ReleaseProcessingResult
    {
        $this->processCalls++;

        throw new \RuntimeException('boom');
    }
}

class TimedOutAdditionalReleaseProcessor extends ReleaseProcessor
{
    public int $processCalls = 0;

    public function __construct(private readonly ProcessTimedOutException $exception) {}

    public function process(ReleaseProcessingContext $context, string $mainTmpPath): ReleaseProcessingResult
    {
        $this->processCalls++;

        throw $this->exception;
    }
}

class StorageUnavailableAdditionalReleaseProcessor extends ReleaseProcessor
{
    public int $processCalls = 0;

    public function __construct() {}

    public function process(ReleaseProcessingContext $context, string $mainTmpPath): ReleaseProcessingResult
    {
        $this->processCalls++;

        return new ReleaseProcessingResult(
            releaseId: (int) $context->release->id,
            guid: (string) $context->release->guid,
            outcome: ProcessingOutcome::StorageUnavailable,
            reason: 'NZB storage is unavailable',
        );
    }
}

class RecordingTempWorkspaceService extends TempWorkspaceService
{
    public int $ensureMainTempPathCalls = 0;

    public int $clearDirectoryCalls = 0;

    public function ensureMainTempPath(
        string $basePath,
        string $guidChar = '',
        string $groupID = '',
        string $workerToken = ''
    ): string {
        $this->ensureMainTempPathCalls++;

        // A stub value only: clearDirectory() below is a counter, so nothing is
        // ever created here and the path never reaches the filesystem.
        return sys_get_temp_dir().'/nntmux-orchestrator-claim-workspace/';
    }

    public function clearDirectory(string $path, bool $preserveRoot = true): void
    {
        $this->clearDirectoryCalls++;
    }
}

class FailingTempWorkspaceService extends RecordingTempWorkspaceService
{
    public function ensureMainTempPath(
        string $basePath,
        string $guidChar = '',
        string $groupID = '',
        string $workerToken = ''
    ): string {
        $this->ensureMainTempPathCalls++;

        throw new \RuntimeException('Additional post-processing temp path "/root/nope/a/" is not writable');
    }
}

class RecordingConsoleOutputService extends ConsoleOutputService
{
    public int $echoDescriptionCalls = 0;

    public int $endOutputCalls = 0;

    /**
     * @var list<string>
     */
    public array $warnings = [];

    public function echoDescription(int $totalReleases): void
    {
        $this->echoDescriptionCalls++;
    }

    public function warning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function endOutput(): void
    {
        $this->endOutputCalls++;
    }
}
