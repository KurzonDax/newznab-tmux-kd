<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Facades\Search;
use App\Models\Release;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\ReleaseRemoverService;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\Releases\ReleaseDeletionProtection;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\ReleaseRemoverBatchingTest;

/** Exercises the actual REGEXP queries and InnoDB boundary in the disposable CI database. */
class ReleaseCleanupSafetyMariaDbTest extends ReleaseRemoverBatchingTest
{
    protected function bootIsolatedDatabase(): void
    {
        if (getenv('CBP_INTEGRATION_DB_DATABASE') !== 'cbp_integration') {
            $this->markTestSkipped('Requires the disposable cbp_integration Sail database.');
        }
        config([
            'database.default' => 'mariadb',
            'database.connections.mariadb.host' => 'mariadb',
            'database.connections.mariadb.database' => 'cbp_integration',
            'database.connections.mariadb.username' => getenv('CBP_INTEGRATION_DB_USERNAME'),
            'database.connections.mariadb.password' => getenv('CBP_INTEGRATION_DB_PASSWORD'),
        ]);
        DB::purge('mariadb');
        Schema::dropIfExists('settings');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        foreach ($this->bootstrapSettings() as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('releases', function (Blueprint $table): void {
            $table->integer('totalpart')->default(1);
            $table->integer('declaredfiles')->default(1);
            $table->integer('passwordstatus')->default(-1);
            $table->integer('categories_id')->default(6000);
        });
        Schema::table('release_files', function (Blueprint $table): void {
            $table->integer('passworded')->default(-1);
        });
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('cleanup-nzbs').DIRECTORY_SEPARATOR]);
    }

    protected function tearDown(): void
    {
        if (DB::getDefaultConnection() === 'mariadb') {
            foreach (['categories', 'root_categories', 'parts', 'binaries', 'collections', 'release_files', 'releases', 'binaryblacklist', 'usenet_groups', 'settings'] as $table) {
                Schema::dropIfExists($table);
            }
            DB::disconnect('cleanup_peer');
        }
        parent::tearDown();
    }

    public function test_par2_and_password_prefilters_require_positive_evidence(): void
    {
        $nzb = app(NzbService::class);
        for ($id = 1; $id <= 8; $id++) {
            DB::table('releases')->insert([
                'id' => $id, 'guid' => str_pad((string) $id, 40, '0', STR_PAD_LEFT),
                'searchname' => $id < 5 ? 'Example.par2' : 'Example password', 'groups_id' => 1,
            ]);
        }
        $this->stored($nzb, 1, ['Example.par2']);
        $this->stored($nzb, 2, ['Example.par2', 'Example.rar']);
        $this->stored($nzb, 3, ['Example.par2']);
        DB::table('releases')->where('id', 2)->update(['totalpart' => 2, 'declaredfiles' => 2]);
        DB::table('releases')->where('id', 3)->update(['declaredfiles' => 2]);
        DB::table('release_files')->insert(['releases_id' => 2, 'name' => 'partial.par2']);
        DB::table('releases')->where('id', 6)->update(['passwordstatus' => ReleaseBrowseService::PASSWD_RAR]);
        DB::table('release_files')->insert(['releases_id' => 7, 'name' => 'archive.rar', 'passworded' => ReleaseBrowseService::PASSWD_RAR]);
        DB::table('releases')->where('id', 8)->update(['passwordstatus' => ReleaseBrowseService::PASSWD_RAR, 'searchname' => 'no password']);
        $images = Mockery::mock(ReleaseImageService::class);
        $images->shouldReceive('delete')->times(3);
        Search::shouldReceive('deleteReleases')->once()->with([1]);
        Search::shouldReceive('deleteReleases')->once()->with([6, 7]);
        $remover = new ReleaseRemoverService(new ReleaseManagementService, $nzb, $images);
        $remover->removeCrap(false, 'full', 'par2only');
        self::assertSame(8, DB::table('releases')->count());
        $remover->removeCrap(true, 'full', 'par2only');
        $remover->removeCrap(true, 'full', 'passworded');
        self::assertSame([2, 3, 4, 5, 8], DB::table('releases')->orderBy('id')->pluck('id')->all());
        self::assertNotFalse($nzb->nzbPath(str_pad('2', 40, '0', STR_PAD_LEFT)));
    }

    public function test_another_connection_cannot_replace_evidence_during_the_locked_deletion_decision(): void
    {
        $guid = str_repeat('a', 40);
        DB::table('releases')->insert(['id' => 1, 'guid' => $guid, 'searchname' => 'a.par2', 'groups_id' => 1]);
        config(['database.connections.cleanup_peer' => config('database.connections.mariadb')]);
        $peer = DB::connection('cleanup_peer');
        $peer->statement('SET SESSION innodb_lock_wait_timeout=1');
        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldReceive('deleteNzb')->once()->with($guid);
        $images = Mockery::mock(ReleaseImageService::class);
        $images->shouldReceive('delete')->once()->with($guid);
        Search::shouldReceive('deleteReleases')->once()->with([1]);
        $attempted = false;
        $deleted = (new ReleaseManagementService)->deleteBatchIfUnclaimed(
            [['id' => 1, 'guid' => $guid]], $nzb, $images,
            evidence: function (Release $release) use ($peer, &$attempted): array {
                try {
                    $peer->transaction(fn () => $peer->table('releases')->where('id', 1)->lockForUpdate()->first());
                    self::fail('The evidence writer acquired a release held by deletion.');
                } catch (QueryException $exception) {
                    self::assertSame(1205, (int) ($exception->errorInfo[1] ?? 0));
                    $attempted = true;
                }

                return ['eligible' => true];
            },
        );
        self::assertTrue($attempted);
        self::assertSame(1, $deleted);
        self::assertNull($peer->table('releases')->find(1));
    }

    public function test_file_writer_cannot_invalidate_evidence_after_the_lifecycle_snapshot(): void
    {
        $guid = str_repeat('b', 40);
        DB::table('releases')->insert(['id' => 1, 'guid' => $guid, 'searchname' => 'password', 'groups_id' => 1]);
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => 'archive.rar', 'passworded' => 1]);
        config(['database.connections.cleanup_peer' => config('database.connections.mariadb')]);
        $peer = DB::connection('cleanup_peer');
        $peer->statement('SET SESSION innodb_lock_wait_timeout=1');
        $attempted = false;
        $changed = false;
        DB::listen(function (QueryExecuted $event) use ($peer, &$attempted, &$changed): void {
            if ($attempted || $event->connectionName !== 'mariadb'
                || ! str_starts_with($event->sql, 'select exists(')
                || ! str_contains($event->sql, '`nzbstatus`')) {
                return;
            }
            $attempted = true;
            try {
                $peer->table('release_files')->where('releases_id', 1)->update(['passworded' => 0]);
                $changed = true;
            } catch (QueryException $exception) {
                self::assertSame(1205, (int) ($exception->errorInfo[1] ?? 0));
            }
        });
        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldReceive('deleteNzb')->once()->with($guid);
        $images = Mockery::mock(ReleaseImageService::class);
        $images->shouldReceive('delete')->once()->with($guid);
        Search::shouldReceive('deleteReleases')->once()->with([1]);
        $deleted = (new ReleaseManagementService)->deleteBatchIfUnclaimed(
            [['id' => 1, 'guid' => $guid]], $nzb, $images,
            evidence: fn (Release $release): array => [
                'eligible' => DB::table('release_files')->where('releases_id', $release->id)->where('passworded', 1)->exists(),
            ],
        );
        self::assertTrue($attempted);
        self::assertFalse($changed, 'Evidence must be locked before establishing the read snapshot.');
        self::assertSame(1, $deleted);
    }

    public function test_cleanup_defers_inside_an_existing_transaction_with_a_stale_snapshot(): void
    {
        $guid = str_repeat('c', 40);
        DB::table('releases')->insert(['id' => 1, 'guid' => $guid, 'searchname' => 'password', 'groups_id' => 1]);
        config(['database.connections.cleanup_peer' => config('database.connections.mariadb')]);
        $peer = DB::connection('cleanup_peer');
        $nzb = Mockery::mock(NzbService::class);
        $images = Mockery::mock(ReleaseImageService::class);
        DB::transaction(function () use ($guid, $peer, $nzb, $images): void {
            DB::table('releases')->where('id', 1)->first();
            $peer->table('releases')->where('id', 1)->update(['nzbstatus' => NzbService::NZB_NONE]);
            self::assertSame(0, (new ReleaseManagementService)->deleteBatchIfUnclaimed(
                [['id' => 1, 'guid' => $guid]], $nzb, $images,
            ));
        });
        self::assertSame(NzbService::NZB_NONE, DB::table('releases')->where('id', 1)->value('nzbstatus'));
    }

    #[DataProvider('cleanupOutcomes')]
    public function test_peer_deletion_defers_during_stream_and_until_cbp_cleanup_finishes(bool $cleanupFails): void
    {
        $this->createWriterSchema();
        $guid = str_repeat('c', 40);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
        DB::table('releases')->insert(['id' => 1, 'guid' => $guid, 'searchname' => 'Example.par2', 'groups_id' => 1,
            'nzbstatus' => 0, 'nzb_creation_claimed_at' => now(), 'nzb_creation_claim_token' => 'writer']);
        DB::table('collections')->insert(['id' => 1, 'releases_id' => 1, 'date' => now()]);
        DB::table('binaries')->insert(['id' => 1, 'collections_id' => 1, 'name' => '"Example.par2" yEnc', 'totalparts' => 1]);
        DB::table('parts')->insert(['binaries_id' => 1, 'number' => 1, 'partnumber' => 1, 'size' => 1, 'messageid' => 'fixture@example.invalid']);
        config(['database.connections.cleanup_peer' => config('database.connections.mariadb')]);
        $peer = DB::connection('cleanup_peer');
        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldNotReceive('deleteNzb');
        $images = Mockery::mock(ReleaseImageService::class);
        $images->shouldNotReceive('delete');
        Search::shouldReceive('deleteReleases')->never();
        $attempt = static function () use ($guid, $nzb, $images): void {
            DB::setDefaultConnection('cleanup_peer');
            try {
                self::assertSame(0, (new ReleaseManagementService)->deleteBatchIfUnclaimed([['id' => 1, 'guid' => $guid]], $nzb, $images));
            } finally {
                DB::setDefaultConnection('mariadb');
            }
        };
        $duringStream = false;
        DB::listen(static function (QueryExecuted $event) use (&$duringStream, $attempt): void {
            if (! $duringStream && str_contains($event->sql, 'SELECT b.collections_id AS collection_id')) {
                $duringStream = true;
                $attempt();
            }
        });
        $cleanup = new class($attempt, $cleanupFails) extends CollectionCleanupService
        {
            public bool $called = false;

            public function __construct(private readonly \Closure $attempt, private readonly bool $fail)
            {
                parent::__construct();
            }

            public function deleteCollectionsAndDescendants(array $collectionIds, string $label = 'CBP cleanup', bool $echoCLI = false): int
            {
                $this->called = true;
                ($this->attempt)();
                if ($this->fail) {
                    throw new \RuntimeException('Injected CBP cleanup failure');
                }

                return parent::deleteCollectionsAndDescendants($collectionIds, $label, $echoCLI);
            }
        };
        $release = Release::query()->findOrFail(1);
        $release->setRelation('category', (object) ['title' => 'Other']);
        $writer = new NzbService($cleanup);
        $result = $writer->createNzbForRelease($release);
        self::assertTrue($result->success, $result->reason);
        self::assertTrue($duringStream);
        self::assertTrue($cleanup->called);
        self::assertSame(1, (int) $peer->table('releases')->value('nzbstatus'));
        self::assertNull($peer->table('releases')->value(NzbCreationCandidateQuery::CLAIM_TOKEN_COLUMN));
        self::assertFileExists($result->path);
        self::assertSame([], glob($result->path.'.tmp.*'));
        if ($cleanupFails) {
            $attempt();
            self::assertSame(1, $peer->table('parts')->count());
            (new CollectionCleanupService)->deleteCollectionsAndDescendants([1]);
        }
        self::assertSame(0, $peer->table('collections')->count());
        self::assertSame(0, $peer->table('parts')->count());
        self::assertTrue(ReleaseDeletionProtection::apply(Release::query())->whereKey(1)->exists());
    }

    #[DataProvider('fixtureSchedules')]
    public function test_synthetic_a_to_d_and_rar_first_controls_survive_real_nzb_schedules(string $schedule): void
    {
        $this->createWriterSchema();
        Schema::table('releases', function (Blueprint $table): void {
            $table->bigInteger('size')->default(2013265920);
            $table->dateTime('postdate')->nullable();
            foreach (['nfostatus', 'iscategorized', 'rarinnerfilecount', 'haspreview', 'jpgstatus', 'predb_id', 'videostatus'] as $column) {
                $table->integer($column)->default(0);
            }
            $table->string('imdbid')->nullable();
        });
        // No executable roots or blacklist rules; unrelated All predicates retain these large mixed releases.
        Schema::create('root_categories', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->boolean('discard_executables')->default(false);
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->integer('id')->primary();
        });
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
        Search::shouldReceive('deleteReleases')->never();
        $nzb = app(NzbService::class);
        foreach (['A' => 83, 'B' => 55, 'C' => 66, 'D' => 68, 'RarFirstOne' => 5, 'RarFirstTwo' => 6] as $label => $declared) {
            $id = DB::table('releases')->count() + 1;
            $base = 'Fixture.Release.'.$label;
            $initial = $base.' - [02/'.$declared.'] - "'.$base.($id <= 4 ? '.mp4.par2' : '.part02.rar').'" yEnc';
            $guid = str_pad((string) $id, 40, '0', STR_PAD_LEFT);
            DB::table('releases')->insert([
                'id' => $id, 'guid' => $guid, 'name' => $initial, 'searchname' => $initial,
                'groups_id' => 1, 'nzbstatus' => NzbService::NZB_NONE, 'passwordstatus' => 0,
                'declaredfiles' => $declared, 'totalpart' => $declared - 1, 'adddate' => now(),
            ]);
            DB::table('collections')->insert(['id' => $id, 'releases_id' => $id, 'date' => now(), 'declaredfiles' => $declared]);
            for ($ordinal = 2; $ordinal <= $declared; $ordinal++) {
                $name = $ordinal === 2 ? $initial : $base.' - ['.$ordinal.'/'.$declared.'] - "'.$base.'.part'.$ordinal.'.rar" yEnc';
                if ($id > 4 && $ordinal === 3) {
                    $name = '"'.$base.'.mp4.par2" yEnc';
                }
                $binaryId = DB::table('binaries')->insertGetId(['collections_id' => $id, 'name' => $name, 'totalparts' => 1]);
                DB::table('parts')->insert(['binaries_id' => $binaryId, 'number' => $binaryId, 'partnumber' => 1,
                    'size' => 33554432, 'messageid' => 'fixture-'.$binaryId.'@example.invalid']);
            }
        }
        self::assertSame(6, DB::table('releases')->where('nzbstatus', 0)->where('passwordstatus', 0)->count());
        if ($schedule === 'fresh_claim' || $schedule === 'stale_claim') {
            self::assertSame(6, NzbCreationCandidateQuery::claimBatch(1, 10, 'fixture-writer')->count());
            if ($schedule === 'stale_claim') {
                DB::table('releases')->update(['nzb_creation_claimed_at' => now()->subDay()]);
            }
        }
        $remover = new ReleaseRemoverService(nzb: $nzb);
        if ($schedule !== 'after_nzb') {
            $remover->removeCrap(true, '2', '');
            self::assertSame(6, DB::table('releases')->count());
            self::assertSame(6, DB::table('collections')->count());
        }
        foreach (Release::query()->get() as $release) {
            $release->setRelation('category', (object) ['title' => 'Other']);
            $result = $nzb->createNzbForRelease($release);
            self::assertTrue($result->success, $result->reason);
            self::assertFileExists($result->path);
            self::assertStringContainsString('.rar', (string) gzdecode((string) file_get_contents($result->path)));
            self::assertSame([], glob($result->path.'.tmp.*'));
        }
        $remover->removeCrap(true, '2', '');
        self::assertSame(6, DB::table('releases')->where('nzbstatus', NzbService::NZB_ADDED)->count());
        self::assertSame(0, DB::table('collections')->count());
        self::assertSame(0, DB::table('parts')->count());
        foreach (Release::query()->get() as $release) {
            self::assertNotFalse($nzb->nzbPath($release->guid));
        }
    }

    /** @return array<string, array{string}> */
    public static function fixtureSchedules(): array
    {
        return ['before NZB' => ['before_nzb'], 'fresh creator claim' => ['fresh_claim'],
            'expired creator claim' => ['stale_claim'], 'after NZB' => ['after_nzb']];
    }

    private function createWriterSchema(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->string('name')->default('Example');
            $table->float('completion')->default(0);
        });
        Schema::table('collections', function (Blueprint $table): void {
            $table->integer('groups_id')->default(1);
            $table->dateTime('date')->nullable();
            $table->string('fromname')->default('fixture@example.invalid');
            $table->integer('declaredfiles')->default(1);
            $table->string('xref')->default('server alt.binaries.test:1');
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('collections_id');
            $table->string('name');
            $table->integer('totalparts');
        });
        Schema::create('parts', function (Blueprint $table): void {
            $table->integer('binaries_id');
            $table->integer('number');
            $table->integer('partnumber');
            $table->integer('size');
            $table->string('messageid');
        });
    }

    /** @return array<string, array{bool}> */
    public static function cleanupOutcomes(): array
    {
        return ['successful cleanup' => [false], 'failed cleanup then retry' => [true]];
    }

    /** @param list<string> $names */
    private function stored(NzbService $nzb, int $id, array $names): void
    {
        $guid = str_pad((string) $id, 40, '0', STR_PAD_LEFT);
        $xml = '<nzb>';
        foreach ($names as $name) {
            $xml .= '<file subject="&quot;'.$name.'&quot; yEnc"/>';
        }
        file_put_contents($nzb->getNzbPath($guid, 0, true), gzencode($xml.'</nzb>'));
    }
}
