<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BlacklistConstants;
use App\Facades\Search;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\ReleaseRemoverService;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
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
    }

    protected function tearDown(): void
    {
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
        $management->shouldReceive('deleteBatch')
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
        $management->shouldReceive('deleteBatch')
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
        $management->shouldReceive('deleteBatch')->once()->andReturn(1);

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
