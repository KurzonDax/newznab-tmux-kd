<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PredbSearchStatus;
use App\Services\Backfill\BackfillConfig;
use App\Services\Backfill\BackfillService;
use App\Services\Binaries\BinariesService;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\PredbSearchLifecycle;
use App\Services\NNTP\NNTPService;
use App\Services\Runners\ReleasesRunner;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PredbSearchLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-17 12:00:00');
        Schema::dropIfExists('predb');
        Schema::create('predb', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->dateTime('predate')->nullable();
            $table->string('source')->default('test');
            $table->tinyInteger('searched')->default(0);
            $table->dateTime('next_predb_search_at')->nullable();
        });
        Schema::dropIfExists('usenet_groups');
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('first_record');
            $table->dateTime('first_record_postdate');
            $table->dateTime('backfill_settled_at')->nullable();
            $table->dateTime('last_updated')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('predb');
        Schema::dropIfExists('usenet_groups');

        parent::tearDown();
    }

    public function test_a_no_match_walks_the_paced_retry_band_then_parks_after_four_attempts(): void
    {
        $this->insertPredb();
        $nameFixing = $this->mock(NameFixingService::class);
        $nameFixing->shouldReceive('matchPredbFulltext')->times(4)->andReturn(0);

        $this->runPredbBatch();
        $this->assertPredbState(PredbSearchStatus::RetryAfterFirstMiss, '2026-08-18 12:00:00');

        $this->runPredbBatch();
        $this->assertPredbState(PredbSearchStatus::RetryAfterFirstMiss, '2026-08-18 12:00:00');

        $this->travelTo('2026-08-18 12:00:00');
        $this->runPredbBatch();
        $this->assertPredbState(PredbSearchStatus::RetryAfterSecondMiss, '2026-08-20 12:00:00');

        $this->travelTo('2026-08-20 12:00:00');
        $this->runPredbBatch();
        $this->assertPredbState(PredbSearchStatus::RetryAfterThirdMiss, '2026-08-24 12:00:00');

        $this->travelTo('2026-08-24 12:00:00');
        $this->runPredbBatch();
        $this->assertPredbState(PredbSearchStatus::Parked, null);

        $this->travelTo('2027-08-28 12:00:00');
        $this->runPredbBatch();
        $this->assertPredbState(PredbSearchStatus::Parked, null);
    }

    public function test_matched_and_flood_titles_are_terminal(): void
    {
        $this->insertPredb(id: 1);
        $this->insertPredb(id: 2, title: 'Another.Valid.Scene.Release-GROUP');

        $nameFixing = $this->mock(NameFixingService::class);
        $nameFixing->shouldReceive('matchPredbFulltext')->once()->andReturn(1);
        $nameFixing->shouldReceive('matchPredbFulltext')->once()->andReturn(-1);

        $this->runPredbBatch(limit: 2);

        $this->assertSame(PredbSearchStatus::Matched->value, (int) DB::table('predb')->find(1)->searched);
        $this->assertSame(PredbSearchStatus::Flood->value, (int) DB::table('predb')->find(2)->searched);

        $this->travelTo('2027-08-17 12:00:00');
        $this->runPredbBatch(limit: 2);

        $this->assertSame(PredbSearchStatus::Matched->value, (int) DB::table('predb')->find(1)->searched);
        $this->assertSame(PredbSearchStatus::Flood->value, (int) DB::table('predb')->find(2)->searched);
    }

    public function test_backend_unavailability_consumes_neither_the_title_nor_an_attempt(): void
    {
        $this->insertPredb();
        $nameFixing = $this->mock(NameFixingService::class);
        $nameFixing->shouldReceive('matchPredbFulltext')
            ->once()
            ->andThrow(new RuntimeException('Search backend unavailable'));

        $this->artisan('releases:fix-names-group', [
            'type' => 'predbft',
            '--limit' => 1,
            '--thread' => 1,
            '--workers' => 1,
        ])->assertFailed();

        $this->assertPredbState(PredbSearchStatus::Unsearched, null);
    }

    public function test_backend_unavailability_rolls_back_outcomes_collected_earlier_in_the_batch(): void
    {
        $this->insertPredb(id: 1);
        $this->insertPredb(id: 2, title: 'Another.Valid.Scene.Release-GROUP');
        $nameFixing = $this->mock(NameFixingService::class);
        $nameFixing->shouldReceive('matchPredbFulltext')
            ->once()
            ->andReturn(0);
        $nameFixing->shouldReceive('matchPredbFulltext')
            ->once()
            ->andThrow(new RuntimeException('Search backend unavailable'));

        $this->artisan('releases:fix-names-group', [
            'type' => 'predbft',
            '--limit' => 2,
            '--thread' => 1,
            '--workers' => 1,
        ])->assertFailed();

        $this->assertSame([0, 0], $this->storedSearchStates());
    }

    public function test_each_cycle_is_bounded_by_the_per_run_limit_times_configured_threads(): void
    {
        config(['nntmux.stream_fork_output' => true]);
        $database = $this->createStub(ConnectionInterface::class);
        $database->method('select')->willReturn([(object) ['num' => 10_000]]);
        $runner = new PredbReleasesRunnerTestDouble(new NameFixingQueryService($database));

        $runner->fixRelNames('predbft', maxPerRun: 25, maxThreads: 3);

        $this->assertCount(3, $runner->commands);
        $this->assertSame(3, $runner->maxProcesses);
        foreach ($runner->commands as $command) {
            $this->assertStringContainsString('predbft', $command);
            $this->assertStringContainsString(' 25 ', $command);
            $this->assertStringContainsString(' 3"', $command);
        }
    }

    public function test_a_backlog_larger_than_one_worker_limit_drains_across_cycles(): void
    {
        foreach (range(1, 5) as $id) {
            $this->insertPredb(id: $id, title: "Valid.Scene.Release.{$id}-GROUP");
        }
        $nameFixing = $this->mock(NameFixingService::class);
        $nameFixing->shouldReceive('matchPredbFulltext')->times(4)->andReturn(0);

        $this->runPredbBatch(limit: 2);

        $this->assertSame([-1, -1, 0, 0, 0], $this->storedSearchStates());

        $this->runPredbBatch(limit: 2);

        $this->assertSame([-1, -1, -1, -1, 0], $this->storedSearchStates());
    }

    public function test_backfill_rearms_parked_titles_in_the_covered_window_but_not_terminal_titles(): void
    {
        DB::table('usenet_groups')->insert([
            'id' => 1,
            'name' => 'alt.binaries.test',
            'first_record' => 1_000,
            'first_record_postdate' => '2026-07-12 00:00:00',
        ]);
        $this->insertPredbWithState(1, PredbSearchStatus::Parked, '2026-07-11 12:00:00');
        $this->insertPredbWithState(2, PredbSearchStatus::Matched, '2026-07-11 12:00:00');
        $this->insertPredbWithState(3, PredbSearchStatus::Flood, '2026-07-11 12:00:00');
        $this->insertPredbWithState(4, PredbSearchStatus::Parked, '2026-07-01 12:00:00');

        $binaries = $this->createMock(BinariesService::class);
        $binaries->method('getMessageBuffer')->willReturn(100);
        $binaries->expects($this->once())
            ->method('scan')
            ->willReturn([
                'firstArticleDate' => '2026-07-10 00:00:00',
                'lastArticleDate' => '2026-07-12 00:00:00',
            ]);
        $nntp = new class extends NNTPService
        {
            public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
            {
                return ['first' => 1, 'last' => 2_000];
            }
        };
        $service = new BackfillService(
            config: new BackfillConfig,
            binaries: $binaries,
            nntp: $nntp,
            predbSearchLifecycle: new PredbSearchLifecycle,
        );

        $service->backfillGroup([
            'id' => 1,
            'name' => 'alt.binaries.test',
            'first_record' => 1_000,
            'first_record_postdate' => '2026-07-12 00:00:00',
            'backfill_target' => 30,
        ], remainingGroups: 0, articles: 100);

        $this->assertSame(PredbSearchStatus::Unsearched->value, (int) DB::table('predb')->find(1)->searched);
        $this->assertSame(PredbSearchStatus::Matched->value, (int) DB::table('predb')->find(2)->searched);
        $this->assertSame(PredbSearchStatus::Flood->value, (int) DB::table('predb')->find(3)->searched);
        $this->assertSame(PredbSearchStatus::Parked->value, (int) DB::table('predb')->find(4)->searched);

        $nameFixing = $this->mock(NameFixingService::class);
        $nameFixing->shouldReceive('matchPredbFulltext')->once()->andReturn(1);
        $this->runPredbBatch();

        $this->assertSame(PredbSearchStatus::Matched->value, (int) DB::table('predb')->find(1)->searched);
    }

    public function test_an_empty_backfill_chunk_does_not_widen_the_next_successful_rearm_window(): void
    {
        DB::table('usenet_groups')->insert([
            'id' => 1,
            'name' => 'alt.binaries.test',
            'first_record' => 1_000,
            'first_record_postdate' => '2026-07-12 00:00:00',
        ]);
        $this->insertPredbWithState(1, PredbSearchStatus::Parked, '2026-07-07 12:00:00');
        $this->insertPredbWithState(2, PredbSearchStatus::Parked, '2026-07-10 12:00:00');

        $binaries = $this->createMock(BinariesService::class);
        $binaries->method('getMessageBuffer')->willReturn(100);
        $binaries->expects($this->exactly(2))
            ->method('scan')
            ->willReturnOnConsecutiveCalls(
                [],
                [
                    'firstArticleDate' => '2026-07-07 00:00:00',
                    'lastArticleDate' => '2026-07-08 00:00:00',
                ],
            );
        $binaries->expects($this->once())
            ->method('postdate')
            ->willReturn(strtotime('2026-07-10 00:00:00'));
        $nntp = new class extends NNTPService
        {
            public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
            {
                return ['first' => 1, 'last' => 2_000];
            }
        };
        $service = new BackfillService(
            config: new BackfillConfig,
            binaries: $binaries,
            nntp: $nntp,
            predbSearchLifecycle: new PredbSearchLifecycle,
        );

        $service->backfillGroup([
            'id' => 1,
            'name' => 'alt.binaries.test',
            'first_record' => 1_000,
            'first_record_postdate' => '2026-07-12 00:00:00',
            'backfill_target' => 30,
        ], remainingGroups: 0, articles: 200);

        $this->assertSame(PredbSearchStatus::Unsearched->value, (int) DB::table('predb')->find(1)->searched);
        $this->assertSame(PredbSearchStatus::Parked->value, (int) DB::table('predb')->find(2)->searched);
    }

    private function runPredbBatch(int $limit = 1): void
    {
        $this->artisan('releases:fix-names-group', [
            'type' => 'predbft',
            '--limit' => $limit,
            '--thread' => 1,
            '--workers' => 1,
        ])->assertSuccessful();
    }

    private function insertPredb(int $id = 1, string $title = 'Valid.Scene.Release.2026-GROUP'): void
    {
        DB::table('predb')->insert([
            'id' => $id,
            'title' => $title,
            'predate' => '2026-08-01 00:00:00',
            'source' => 'test',
            'searched' => PredbSearchStatus::Unsearched->value,
            'next_predb_search_at' => null,
        ]);
    }

    private function assertPredbState(PredbSearchStatus $status, ?string $nextSearchAt): void
    {
        $predb = DB::table('predb')->find(1);

        $this->assertSame($status->value, (int) $predb->searched);
        $this->assertSame($nextSearchAt, $predb->next_predb_search_at);
    }

    private function insertPredbWithState(int $id, PredbSearchStatus $status, string $predate): void
    {
        DB::table('predb')->insert([
            'id' => $id,
            'title' => "Valid.Scene.Release.{$id}-GROUP",
            'predate' => $predate,
            'source' => 'test',
            'searched' => $status->value,
            'next_predb_search_at' => '2026-09-01 00:00:00',
        ]);
    }

    /**
     * @return list<int>
     */
    private function storedSearchStates(): array
    {
        return DB::table('predb')
            ->orderBy('id')
            ->pluck('searched')
            ->map(static fn (mixed $status): int => (int) $status)
            ->all();
    }
}

class PredbReleasesRunnerTestDouble extends ReleasesRunner
{
    /** @var list<string> */
    public array $commands = [];

    public int $maxProcesses = 0;

    public function __construct(NameFixingQueryService $queries)
    {
        parent::__construct($queries);
    }

    protected function runStreamingCommands(
        array $commands,
        int $maxProcesses,
        string $desc,
        ?callable $onComplete = null,
    ): void {
        $this->commands = array_values($commands);
        $this->maxProcesses = $maxProcesses;
    }
}
