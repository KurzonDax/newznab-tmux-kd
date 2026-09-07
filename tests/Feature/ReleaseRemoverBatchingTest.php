<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BlacklistConstants;
use App\Facades\Search;
use App\Models\Release;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\ReleaseRemoverService;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class ReleaseRemoverBatchingTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'innerfileblacklist' => '',
            'releaseprocessingtimeout' => '120',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        config()->set('nntmux.echocli', false);

        Schema::dropIfExists('release_files');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('binaryblacklist');
        Schema::dropIfExists('usenet_groups');

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('binaryblacklist', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('groupname');
            $table->text('regex');
            $table->unsignedTinyInteger('status');
            $table->unsignedTinyInteger('optype');
            $table->unsignedTinyInteger('msgcol');
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid', 40);
            $table->string('searchname');
            $table->integer('nzbstatus')->default(NzbService::NZB_ADDED);
            $table->dateTime('nzb_creation_claimed_at')->nullable();
            $table->string('nzb_creation_claim_token')->nullable();
            $table->string('fromname')->nullable();
            $table->unsignedInteger('groups_id');
            $table->dateTime('adddate')->nullable();
            $table->dateTime('additional_pp_claimed_at')->nullable();
            $table->dateTime('recovery_claimed_at')->nullable();
        });
        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
        });
        Schema::dropIfExists('collections');
        Schema::create('collections', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('releases_id')->nullable()->index();
        });
        NzbCreationCandidateQuery::flushCapabilityCache();
    }

    protected function tearDown(): void
    {
        NzbCreationCandidateQuery::flushCapabilityCache();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_blacklist_removal_is_not_limited_to_one_hundred_search_results(): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
        DB::table('binaryblacklist')->insert([
            'groupname' => 'alt.binaries.*',
            'regex' => '^blocked-',
            'status' => BlacklistConstants::BLACKLIST_ENABLED,
            'optype' => BlacklistConstants::OPTYPE_BLACKLIST,
            'msgcol' => BlacklistConstants::BLACKLIST_FIELD_SUBJECT,
        ]);

        DB::table('releases')->insert(collect(range(1, 125))->map(static fn (int $id): array => [
            'id' => $id,
            'guid' => str_pad((string) $id, 40, '0', STR_PAD_LEFT),
            'searchname' => 'blocked-'.$id,
            'fromname' => 'poster',
            'groups_id' => 1,
            'adddate' => now(),
        ])->all());

        $management = Mockery::mock(ReleaseManagementService::class);
        $management->shouldReceive('deleteBatchIfUnclaimed')
            ->once()
            ->withArgs(static fn ($releases): bool => $releases->count() === 125)
            ->andReturn(125);

        $service = new ReleaseRemoverService(
            $management,
            Mockery::mock(NzbService::class),
            Mockery::mock(ReleaseImageService::class)
        );

        self::assertTrue($service->removeCrap(true, 'full', 'blacklist'));
    }

    public function test_sweeps_exclude_live_processing_claims_but_include_stale_claims(): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
        DB::table('binaryblacklist')->insert([
            'groupname' => 'alt.binaries.*',
            'regex' => '^blocked-',
            'status' => BlacklistConstants::BLACKLIST_ENABLED,
            'optype' => BlacklistConstants::OPTYPE_BLACKLIST,
            'msgcol' => BlacklistConstants::BLACKLIST_FIELD_SUBJECT,
        ]);

        $live = now();
        $stale = now()->subHour();

        DB::table('releases')->insert([
            $this->releaseRow(1, additionalClaimedAt: $live),
            $this->releaseRow(2, recoveryClaimedAt: $live),
            $this->releaseRow(3, additionalClaimedAt: $stale),
            $this->releaseRow(4, recoveryClaimedAt: $stale),
            $this->releaseRow(5),
        ]);

        $management = Mockery::mock(ReleaseManagementService::class);
        $management->shouldReceive('deleteBatchIfUnclaimed')
            ->once()
            ->withArgs(static fn ($releases): bool => $releases->pluck('id')->map(intval(...))->all() === [3, 4, 5])
            ->andReturn(3);

        $service = new ReleaseRemoverService(
            $management,
            Mockery::mock(NzbService::class),
            Mockery::mock(ReleaseImageService::class)
        );

        self::assertTrue($service->removeCrap(true, 'full', 'blacklist'));
    }

    public function test_invalid_blacklist_regex_does_not_prevent_valid_rules_from_running(): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
        DB::table('binaryblacklist')->insert([
            [
                'groupname' => 'alt.binaries.*',
                'regex' => '[invalid',
                'status' => 1,
                'optype' => 1,
                'msgcol' => 1,
            ],
            [
                'groupname' => 'alt.binaries.*',
                'regex' => '^blocked$',
                'status' => 1,
                'optype' => 1,
                'msgcol' => 1,
            ],
        ]);
        DB::table('releases')->insert([
            'id' => 1,
            'guid' => str_repeat('a', 40),
            'searchname' => 'blocked',
            'fromname' => 'poster',
            'groups_id' => 1,
            'adddate' => now(),
        ]);

        $management = Mockery::mock(ReleaseManagementService::class);
        $management->shouldReceive('deleteBatchIfUnclaimed')->once()->andReturn(1);

        $service = new ReleaseRemoverService(
            $management,
            Mockery::mock(NzbService::class),
            Mockery::mock(ReleaseImageService::class)
        );

        self::assertTrue($service->removeCrap(true, 'full', 'blacklist'));
    }

    public function test_release_management_batches_search_and_database_deletion(): void
    {
        DB::table('releases')->insert([
            [
                'id' => 1,
                'guid' => str_repeat('a', 40),
                'searchname' => 'one',
                'fromname' => 'poster',
                'groups_id' => 1,
                'adddate' => now(),
            ],
            [
                'id' => 2,
                'guid' => str_repeat('b', 40),
                'searchname' => 'two',
                'fromname' => 'poster',
                'groups_id' => 1,
                'adddate' => now(),
            ],
        ]);

        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldReceive('deleteNzb')->twice()->andReturnTrue();
        $images = Mockery::mock(ReleaseImageService::class);
        $images->shouldReceive('delete')->twice();
        Search::shouldReceive('deleteReleases')->once()->with([1, 2]);

        $deleted = (new ReleaseManagementService)->deleteBatch([
            (object) ['id' => 1, 'guid' => str_repeat('a', 40)],
            (object) ['id' => 2, 'guid' => str_repeat('b', 40)],
        ], $nzb, $images);

        self::assertSame(2, $deleted);
        self::assertSame(0, DB::table('releases')->count());
    }

    public function test_protected_batch_rechecks_claims_acquired_after_candidate_selection(): void
    {
        DB::table('releases')->insert([
            $this->releaseRow(1),
            $this->releaseRow(2),
        ]);

        $selected = DB::table('releases')
            ->orderBy('id')
            ->get(['id', 'guid']);

        DB::table('releases')->where('id', 1)->update(['additional_pp_claimed_at' => now()]);
        DB::table('releases')->where('id', 2)->update(['recovery_claimed_at' => now()]);

        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldNotReceive('deleteNzb');
        $images = Mockery::mock(ReleaseImageService::class);
        $images->shouldNotReceive('delete');
        Search::shouldReceive('deleteReleases')->never();

        $deleted = (new ReleaseManagementService)->deleteBatchIfUnclaimed($selected, $nzb, $images);

        self::assertSame(0, $deleted);
        self::assertSame([1, 2], DB::table('releases')->orderBy('id')->pluck('id')->map(intval(...))->all());
    }

    public function test_routine_deletion_defers_pending_claimed_and_linked_releases_until_creation_finishes(): void
    {
        DB::table('releases')->insert(array_map(fn (int $id): array => $this->releaseRow($id), range(1, 6)));
        $selected = DB::table('releases')->orderBy('id')->get(['id', 'guid']);
        DB::table('releases')->whereIn('id', [1, 2])->update(['nzbstatus' => NzbService::NZB_NONE]);
        DB::table('releases')->where('id', 2)->update(['nzb_creation_claimed_at' => now()->subDay()]);
        DB::table('releases')->where('id', 3)->update(['nzb_creation_claimed_at' => now()]);
        DB::table('collections')->insert(['releases_id' => 4]);
        DB::table('releases')->where('id', 5)->update(['nzb_creation_claimed_at' => now()->subDay()]);

        $nzb = Mockery::mock(NzbService::class);
        $images = Mockery::mock(ReleaseImageService::class);
        foreach ([5, 6] as $id) {
            $guid = $this->releaseRow($id)['guid'];
            $nzb->shouldReceive('deleteNzb')->once()->with($guid);
            $images->shouldReceive('delete')->once()->with($guid);
        }
        Search::shouldReceive('deleteReleases')->once()->with([5, 6]);

        self::assertSame(2, (new ReleaseManagementService)->deleteBatchIfUnclaimed($selected, $nzb, $images));
        self::assertSame([1, 2, 3, 4], DB::table('releases')->orderBy('id')->pluck('id')->all());
        self::assertSame(1, DB::table('collections')->count());
    }

    public function test_a_later_candidate_failure_still_cleans_up_previously_committed_deletions(): void
    {
        DB::table('releases')->insert([$this->releaseRow(1), $this->releaseRow(2)]);
        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldReceive('deleteNzb')->once()->with($this->releaseRow(1)['guid']);
        $images = Mockery::mock(ReleaseImageService::class);
        $images->shouldReceive('delete')->once()->with($this->releaseRow(1)['guid']);
        Search::shouldReceive('deleteReleases')->once()->with([1]);
        try {
            (new ReleaseManagementService)->deleteBatchIfUnclaimed(
                DB::table('releases')->orderBy('id')->get(), $nzb, $images,
                evidence: static function (Release $release): array {
                    if ($release->id === 2) {
                        throw new \RuntimeException('Injected later failure');
                    }

                    return ['eligible' => true];
                },
            );
            self::fail('Expected the injected failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Injected later failure', $exception->getMessage());
        }
        self::assertSame([2], DB::table('releases')->pluck('id')->all());
    }

    public function test_criteria_advances_past_a_fully_protected_page_and_logs_only_actual_deletions(): void
    {
        $rows = array_map(fn (int $id): array => $this->releaseRow($id, additionalClaimedAt: $id <= 500 ? now() : null), range(1, 501));
        DB::table('releases')->insert($rows);
        $events = [];
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->andReturnUsing(static function (string $message, array $context) use (&$events): void {
            $events[] = [$message, $context];
        });
        Log::shouldReceive('channel')->with('daily')->andReturn($logger);
        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldReceive('deleteNzb')->once()->with($rows[500]['guid']);
        $images = Mockery::mock(ReleaseImageService::class);
        $images->shouldReceive('delete')->once()->with($rows[500]['guid']);
        Search::shouldReceive('deleteReleases')->once()->with([501]);
        $management = new ReleaseManagementService;
        self::assertSame(1, $management->deleteBatchIfUnclaimed(DB::table('releases')->get(), $nzb, $images, dryRun: true));
        self::assertSame([], array_values(array_filter($events, static fn (array $event): bool => $event[0] === 'release_deleted')));
        self::assertSame(501, DB::table('releases')->count());

        (new ReleaseRemoverService($management, $nzb, $images))->removeByCriteria(['ignore', 'searchname=like=blocked']);

        self::assertSame(500, DB::table('releases')->count());
        $deleted = array_values(array_filter($events, static fn (array $event): bool => $event[0] === 'release_deleted'));
        self::assertCount(1, $deleted);
        self::assertSame(501, $deleted[0][1]['release_id']);
        self::assertSame('userCriteria', $deleted[0][1]['reason']);
        self::assertSame(500, $events[0][1]['protected_or_deferred']);
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseRow(
        int $id,
        mixed $additionalClaimedAt = null,
        mixed $recoveryClaimedAt = null,
    ): array {
        return [
            'id' => $id,
            'guid' => str_pad((string) $id, 40, '0', STR_PAD_LEFT),
            'searchname' => 'blocked-'.$id,
            'fromname' => 'poster',
            'groups_id' => 1,
            'adddate' => now(),
            'additional_pp_claimed_at' => $additionalClaimedAt,
            'recovery_claimed_at' => $recoveryClaimedAt,
        ];
    }
}
