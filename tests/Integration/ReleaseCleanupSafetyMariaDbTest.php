<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Facades\Search;
use App\Models\Release;
use App\Services\BlacklistService;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\PosterIdentityBlacklistService;
use App\Services\ReleaseImageService;
use App\Services\ReleaseRemoverService;
use App\Services\Releases\CollectionDeletionSelection;
use App\Services\Releases\CollectionSweepLease;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\Releases\ReleaseDeletionProtection;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
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
            foreach (['kept_releases', 'categories', 'root_categories', 'parts', 'binaries', 'collections', 'release_files', 'release_music_identifications', 'release_audio_evidence', 'releases', 'binaryblacklist', 'usenet_groups', 'settings'] as $table) {
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

            public function deleteCollectionsAndDescendants(array $collectionIds, string $label = 'CBP cleanup', bool $echoCLI = false, ?int $expectedReleaseId = null, bool $screenAdmission = true, ?CollectionDeletionSelection $selection = null, ?CollectionSweepLease $lease = null): int
            {
                $this->called = true;
                ($this->attempt)();
                if ($this->fail) {
                    throw new \RuntimeException('Injected CBP cleanup failure');
                }

                return parent::deleteCollectionsAndDescendants($collectionIds, $label, $echoCLI, $expectedReleaseId, $screenAdmission, $selection, $lease);
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

    public function test_widening_the_blacklist_group_scope_makes_it_text_and_drops_only_its_own_index(): void
    {
        $this->rebuildBlacklistAsBeforeTheGroupScopeWidening();

        $this->groupScopeWidening()->up();

        self::assertSame([
            '`id` int(10) unsigned NOT NULL AUTO_INCREMENT',
            '`groupname` text DEFAULT NULL',
            '`regex` varchar(2000) NOT NULL',
            '`msgcol` int(10) unsigned NOT NULL DEFAULT 1',
            '`optype` int(10) unsigned NOT NULL DEFAULT 1',
            '`status` int(10) unsigned NOT NULL DEFAULT 1',
            '`description` varchar(1000) DEFAULT NULL',
            '`last_activity` date DEFAULT NULL',
            'PRIMARY KEY (`id`)',
            'KEY `ix_binaryblacklist_status` (`status`)',
        ], $this->blacklistDefinition());
    }

    public function test_widening_the_blacklist_group_scope_works_where_its_index_was_never_created(): void
    {
        $this->rebuildBlacklistAsBeforeTheGroupScopeWidening();
        DB::statement('ALTER TABLE `binaryblacklist` DROP INDEX `ix_binaryblacklist_groupname`');

        $this->groupScopeWidening()->up();

        self::assertContains('`groupname` text DEFAULT NULL', $this->blacklistDefinition());
    }

    public function test_widening_the_blacklist_group_scope_leaves_the_stored_rules_as_they_were(): void
    {
        $this->rebuildBlacklistAsBeforeTheGroupScopeWidening();
        $stored = $this->storeThreeBlacklistRules();

        $this->groupScopeWidening()->up();

        self::assertCount(3, $stored);
        self::assertSame($stored, $this->blacklistRules());
    }

    public function test_widening_the_blacklist_group_scope_a_second_time_changes_nothing(): void
    {
        $this->rebuildBlacklistAsBeforeTheGroupScopeWidening();
        $stored = $this->storeThreeBlacklistRules();
        $this->groupScopeWidening()->up();
        $widened = $this->blacklistDefinition();

        $this->groupScopeWidening()->up();

        self::assertSame($widened, $this->blacklistDefinition());
        self::assertSame($stored, $this->blacklistRules());
    }

    public function test_the_schema_dump_shows_the_blacklist_table_as_the_widening_leaves_it(): void
    {
        $this->rebuildBlacklistAsBeforeTheGroupScopeWidening();
        $this->groupScopeWidening()->up();
        $dump = (string) file_get_contents(database_path('schema/mariadb-schema.sql'));

        self::assertSame($this->blacklistDefinition(), $this->blacklistDefinitionLines($dump));
        self::assertStringContainsString("'2026_10_12_130000_widen_binaryblacklist_groupname_to_text'", $dump);
    }

    public function test_a_release_whose_music_identification_was_rerun_cannot_be_deleted_before_the_supersedes_link_is_relaxed(): void
    {
        $this->createMusicIdentificationTablesAsBeforeSupersedesSetNull();
        $this->releaseWithIdentificationChain(1);

        try {
            DB::table('releases')->where('id', 1)->delete();
            self::fail('Deleting a release with a chained identification was expected to fail.');
        } catch (QueryException $exception) {
            self::assertSame('23000', $exception->getCode());
        }
    }

    public function test_a_release_whose_music_identification_was_rerun_can_be_deleted_once_the_supersedes_link_sets_null(): void
    {
        $this->createMusicIdentificationTablesAsBeforeSupersedesSetNull();
        $this->releaseWithIdentificationChain(1);

        $this->supersedesSetNull()->up();
        DB::table('releases')->where('id', 1)->delete();

        self::assertSame(0, DB::table('releases')->where('id', 1)->count());
        self::assertSame(0, DB::table('release_music_identifications')->where('releases_id', 1)->count());
    }

    public function test_deleting_the_oldest_identification_leaves_the_newer_ones_with_the_first_survivor_unlinked(): void
    {
        $this->createMusicIdentificationTablesAsBeforeSupersedesSetNull();
        [$oldest, $middle, $newest] = $this->releaseWithIdentificationChain(1);

        $this->supersedesSetNull()->up();
        DB::table('release_music_identifications')->where('id', $oldest)->delete();

        self::assertSame(
            [$middle => null, $newest => $middle],
            DB::table('release_music_identifications')->orderBy('id')->pluck('supersedes_id', 'id')
                ->map(static fn (mixed $id): ?int => $id === null ? null : (int) $id)->all(),
        );
    }

    public function test_deleting_the_audio_evidence_removes_its_identifications_once_the_supersedes_link_sets_null(): void
    {
        $this->createMusicIdentificationTablesAsBeforeSupersedesSetNull();
        $this->releaseWithIdentificationChain(1);

        $this->supersedesSetNull()->up();
        DB::table('release_audio_evidence')->where('releases_id', 1)->delete();

        self::assertSame(0, DB::table('release_music_identifications')->count());
        self::assertSame(1, DB::table('releases')->where('id', 1)->count());
    }

    public function test_relaxing_the_supersedes_link_a_second_time_leaves_the_same_rules(): void
    {
        $this->createMusicIdentificationTablesAsBeforeSupersedesSetNull();

        $this->supersedesSetNull()->up();
        $this->supersedesSetNull()->up();

        self::assertSame(['DELETE_RULE' => 'SET NULL', 'UPDATE_RULE' => 'CASCADE'], $this->supersedesRules());
    }

    public function test_rolling_back_the_supersedes_link_restores_restrict_on_delete(): void
    {
        $this->createMusicIdentificationTablesAsBeforeSupersedesSetNull();
        $this->supersedesSetNull()->up();

        $this->supersedesSetNull()->down();

        self::assertSame(['DELETE_RULE' => 'RESTRICT', 'UPDATE_RULE' => 'CASCADE'], $this->supersedesRules());
    }

    public function test_the_schema_dump_shows_the_supersedes_link_setting_null_on_delete(): void
    {
        $dump = (string) file_get_contents(database_path('schema/mariadb-schema.sql'));

        self::assertStringContainsString(
            'CONSTRAINT `FK_rmi_supersedes` FOREIGN KEY (`supersedes_id`) REFERENCES `release_music_identifications` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
            $dump,
        );
        self::assertStringContainsString("'2026_10_16_230000_set_null_on_delete_for_release_music_identification_supersedes'", $dump);
    }

    public function test_a_poster_blocked_across_twelve_groups_is_stored_with_the_group_scope_that_was_previewed(): void
    {
        $this->rebuildBlacklistAsBeforeTheGroupScopeWidening();
        $this->groupScopeWidening()->up();
        $poster = 'wide-poster@example.test';
        $this->releasesInTwelveGroups($poster);
        $this->useACipherKey();
        $service = app(PosterIdentityBlacklistService::class);

        $confirmation = $service->previewForConfirmation($poster, 'invented-admin');
        $rule = $service->createOrEnable($poster, 'invented-admin', $confirmation['token']);

        $previewed = $confirmation['preview']['groupname'];
        self::assertGreaterThan(255, strlen($previewed));
        self::assertSame($previewed, DB::table('binaryblacklist')->where('id', $rule->id)->value('groupname'));
    }

    public function test_a_poster_blocked_across_twelve_groups_is_blocked_in_each_of_them_and_in_no_unrelated_group(): void
    {
        $this->rebuildBlacklistAsBeforeTheGroupScopeWidening();
        $this->groupScopeWidening()->up();
        $poster = 'wide-poster@example.test';
        $groups = $this->releasesInTwelveGroups($poster);
        DB::table('usenet_groups')->insert(['id' => 13, 'name' => 'alt.binaries.elsewhere.attic']);
        $this->useACipherKey();
        $service = app(PosterIdentityBlacklistService::class);
        $service->createOrEnable($poster, 'invented-admin', $service->previewForConfirmation($poster, 'invented-admin')['token']);
        $header = ['Subject' => 'Invented post [1/2] yEnc (1/10)', 'From' => $poster, 'Message-ID' => '<invented-1@example.test>'];
        $blacklists = new BlacklistService;

        foreach ($groups as $group) {
            self::assertTrue($blacklists->isBlackListed($header, $group), $group);
        }
        self::assertFalse($blacklists->isBlackListed($header, 'alt.binaries.elsewhere.attic'));
    }

    public function test_reversing_the_group_scope_widening_restores_varchar_255_and_the_index(): void
    {
        $this->rebuildBlacklistAsBeforeTheGroupScopeWidening();
        $stored = $this->storeThreeBlacklistRules();
        $migration = $this->groupScopeWidening();
        $migration->up();

        $migration->down();

        self::assertSame([
            '`id` int(10) unsigned NOT NULL AUTO_INCREMENT',
            '`groupname` varchar(255) DEFAULT NULL',
            '`regex` varchar(2000) NOT NULL',
            '`msgcol` int(10) unsigned NOT NULL DEFAULT 1',
            '`optype` int(10) unsigned NOT NULL DEFAULT 1',
            '`status` int(10) unsigned NOT NULL DEFAULT 1',
            '`description` varchar(1000) DEFAULT NULL',
            '`last_activity` date DEFAULT NULL',
            'PRIMARY KEY (`id`)',
            'KEY `ix_binaryblacklist_status` (`status`)',
            'KEY `ix_binaryblacklist_groupname` (`groupname`)',
        ], $this->blacklistDefinition());
        self::assertSame($stored, $this->blacklistRules());
    }

    public function test_reversing_the_group_scope_widening_refuses_while_a_stored_scope_is_longer_than_255_characters(): void
    {
        $this->rebuildBlacklistAsBeforeTheGroupScopeWidening();
        $migration = $this->groupScopeWidening();
        $migration->up();
        $scope = $this->groupScopeOfLength(256);
        DB::table('binaryblacklist')->insert(['groupname' => $scope, 'regex' => '^invented-poster$']);
        $widened = $this->blacklistDefinition();
        $refusal = null;

        try {
            $migration->down();
        } catch (\RuntimeException $exception) {
            $refusal = $exception->getMessage();
        }

        self::assertNotNull($refusal, 'A group scope of 256 characters was allowed back into VARCHAR(255).');
        self::assertStringContainsString('longer than 255 characters', $refusal);
        self::assertSame($widened, $this->blacklistDefinition());
        self::assertSame($scope, DB::table('binaryblacklist')->value('groupname'));
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
            $table->index('releases_id', 'ix_collection_releaseid');
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('collections_id');
            $table->unsignedInteger('filenumber')->default(0);
            $table->string('name');
            $table->integer('totalparts');
            $table->index(['collections_id', 'filenumber'], 'ix_binaries_collection_filenumber');
        });
        Schema::create('parts', function (Blueprint $table): void {
            $table->integer('binaries_id');
            $table->integer('number');
            $table->integer('partnumber');
            $table->integer('size');
            $table->string('messageid');
            $table->primary(['binaries_id', 'partnumber']);
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

    /** binaryblacklist as database/schema/mariadb-schema.sql defined it before issue #1065's migration. */
    private function rebuildBlacklistAsBeforeTheGroupScopeWidening(): void
    {
        Schema::drop('binaryblacklist');
        DB::statement('CREATE TABLE `binaryblacklist` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `groupname` varchar(255) DEFAULT NULL,
            `regex` varchar(2000) NOT NULL,
            `msgcol` int(10) unsigned NOT NULL DEFAULT 1,
            `optype` int(10) unsigned NOT NULL DEFAULT 1,
            `status` int(10) unsigned NOT NULL DEFAULT 1,
            `description` varchar(1000) DEFAULT NULL,
            `last_activity` date DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `ix_binaryblacklist_groupname` (`groupname`),
            KEY `ix_binaryblacklist_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /**
     * A rule for every group, a disabled whitelist rule for one group, and a scope of exactly
     * 255 characters, the longest the column held before the widening.
     *
     * @return list<array<string, mixed>>
     */
    private function storeThreeBlacklistRules(): array
    {
        DB::table('binaryblacklist')->insert([
            ['groupname' => null, 'regex' => '^invented-everywhere$', 'msgcol' => 1, 'optype' => 1, 'status' => 1, 'description' => 'Every group', 'last_activity' => null],
            ['groupname' => 'alt\.binaries\.invented\.attic', 'regex' => '^invented-attic$', 'msgcol' => 2, 'optype' => 2, 'status' => 0, 'description' => null, 'last_activity' => '2026-10-01'],
            ['groupname' => $this->groupScopeOfLength(255), 'regex' => '^invented-wide$', 'msgcol' => 2, 'optype' => 1, 'status' => 1, 'description' => 'Cut at 255', 'last_activity' => null],
        ]);

        return $this->blacklistRules();
    }

    /** A group scope cut off at that many characters, as the old column left one. */
    private function groupScopeOfLength(int $characters): string
    {
        return str_pad('^(?:alt\.binaries\.invented\.shelf\-01', $characters, '|alt\.binaries\.invented\.shelf\-02');
    }

    /** @return list<array<string, mixed>> */
    private function blacklistRules(): array
    {
        return DB::table('binaryblacklist')->orderBy('id')->get()->map(static fn (object $rule): array => (array) $rule)->all();
    }

    /** @return list<string> The live table's column and key lines, as the schema dump writes them. */
    private function blacklistDefinition(): array
    {
        $created = (array) DB::selectOne('SHOW CREATE TABLE `binaryblacklist`');

        return $this->blacklistDefinitionLines((string) $created['Create Table']);
    }

    /** @return list<string> */
    private function blacklistDefinitionLines(string $sql): array
    {
        self::assertSame(1, preg_match('/^CREATE TABLE `binaryblacklist` \(\n(.*?)\n\) ENGINE=/ms', $sql, $definition));

        return array_map(static fn (string $line): string => rtrim(trim($line), ','), explode("\n", $definition[1]));
    }

    private function groupScopeWidening(): Migration
    {
        return require database_path('migrations/2026_10_12_130000_widen_binaryblacklist_groupname_to_text.php');
    }

    private function supersedesSetNull(): Migration
    {
        return require database_path('migrations/2026_10_16_230000_set_null_on_delete_for_release_music_identification_supersedes.php');
    }

    /** Both tables exactly as `mariadb-schema.sql` defined them before the supersedes link set null on delete. */
    private function createMusicIdentificationTablesAsBeforeSupersedesSetNull(): void
    {
        DB::statement('CREATE TABLE `release_audio_evidence` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `releases_id` int(10) unsigned NOT NULL COMMENT \'FK to releases.id\',
            `revision` int(10) unsigned NOT NULL,
            `evidence_hash` char(64) NOT NULL,
            `schema_version` smallint(5) unsigned NOT NULL,
            `provenance` varchar(32) NOT NULL,
            `release_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`release_snapshot`)),
            `archive_manifest_complete` tinyint(1) DEFAULT NULL,
            `source_file_complete` tinyint(1) DEFAULT NULL,
            `source_starts_at_zero` tinyint(1) DEFAULT NULL,
            `whole_duration_reliable` tinyint(1) DEFAULT NULL,
            `only_one_track_probed` tinyint(1) DEFAULT NULL,
            `nzb_manifest` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`nzb_manifest`)),
            `archive_manifest` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`archive_manifest`)),
            `sidecar_manifest` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`sidecar_manifest`)),
            `captured_at` timestamp NOT NULL,
            `created_at` timestamp NULL DEFAULT NULL,
            `updated_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `release_audio_evidence_releases_id_revision_unique` (`releases_id`,`revision`),
            KEY `release_audio_evidence_releases_id_evidence_hash_index` (`releases_id`,`evidence_hash`),
            KEY `release_audio_evidence_provenance_index` (`provenance`),
            CONSTRAINT `release_audio_evidence_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC');
        DB::statement('CREATE TABLE `release_music_identifications` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `releases_id` int(10) unsigned NOT NULL,
            `release_audio_evidence_id` int(10) unsigned NOT NULL,
            `evidence_hash` char(64) NOT NULL,
            `state` varchar(32) NOT NULL,
            `score` smallint(5) unsigned NOT NULL DEFAULT 0,
            `band` varchar(16) NOT NULL,
            `accepted_scope` varchar(32) DEFAULT NULL,
            `musicbrainz_recording_id` char(36) DEFAULT NULL,
            `musicbrainz_release_id` char(36) DEFAULT NULL,
            `musicbrainz_release_group_id` char(36) DEFAULT NULL,
            `accepted_title` text DEFAULT NULL,
            `accepted_edition_title` text DEFAULT NULL,
            `accepted_aliases` text DEFAULT NULL,
            `accepted_artist_credit` text DEFAULT NULL,
            `original_release_date` varchar(10) DEFAULT NULL,
            `edition_release_date` varchar(10) DEFAULT NULL,
            `reasons` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`reasons`)),
            `feature_contributions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`feature_contributions`)),
            `runner_up_margin` smallint(6) DEFAULT NULL,
            `attempt_count` smallint(5) unsigned NOT NULL DEFAULT 1,
            `lease_token` varchar(64) DEFAULT NULL,
            `lease_expires_at` timestamp NULL DEFAULT NULL,
            `next_attempt_at` timestamp NULL DEFAULT NULL,
            `last_operational_error` text DEFAULT NULL,
            `algorithm_version` varchar(64) NOT NULL,
            `resolver_version` varchar(64) NOT NULL,
            `normalizer_version` varchar(64) NOT NULL,
            `scorer_version` varchar(64) NOT NULL,
            `policy_version` varchar(64) NOT NULL,
            `mirror_replicated_at` timestamp NULL DEFAULT NULL,
            `mirror_searched_at` timestamp NULL DEFAULT NULL,
            `acoustid_looked_up_at` timestamp NULL DEFAULT NULL,
            `supersedes_id` bigint(20) unsigned DEFAULT NULL,
            `decided_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT NULL,
            `updated_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `release_music_identity_version` (`releases_id`,`evidence_hash`,`algorithm_version`),
            KEY `release_music_identity_retry` (`state`,`next_attempt_at`),
            KEY `FK_rmi_rae` (`release_audio_evidence_id`),
            KEY `FK_rmi_supersedes` (`supersedes_id`),
            KEY `release_music_identity_release_group` (`musicbrainz_release_group_id`),
            KEY `release_music_identity_release` (`musicbrainz_release_id`),
            CONSTRAINT `FK_rmi_rae` FOREIGN KEY (`release_audio_evidence_id`) REFERENCES `release_audio_evidence` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `FK_rmi_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `FK_rmi_supersedes` FOREIGN KEY (`supersedes_id`) REFERENCES `release_music_identifications` (`id`) ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC');
    }

    /**
     * One release with one evidence row and three identifications, each newer one superseding the one before.
     *
     * @return list<int> The identification ids, oldest first.
     */
    private function releaseWithIdentificationChain(int $releaseId): array
    {
        $hash = str_repeat('e', 64);
        DB::table('releases')->insert([
            'id' => $releaseId, 'guid' => str_pad((string) $releaseId, 40, '0', STR_PAD_LEFT),
            'searchname' => 'Kenny Neal - Bloodline - 2016, FLAC', 'groups_id' => 1,
        ]);
        $evidenceId = (int) DB::table('release_audio_evidence')->insertGetId([
            'releases_id' => $releaseId, 'revision' => 1, 'evidence_hash' => $hash, 'schema_version' => 1,
            'provenance' => 'test', 'release_snapshot' => '{}', 'nzb_manifest' => '[]',
            'archive_manifest' => '[]', 'sidecar_manifest' => '[]', 'captured_at' => now(),
        ]);
        $ids = [];
        $supersedes = null;
        foreach (['v1', 'v2', 'v3'] as $algorithm) {
            $supersedes = $ids[] = (int) DB::table('release_music_identifications')->insertGetId([
                'releases_id' => $releaseId, 'release_audio_evidence_id' => $evidenceId, 'evidence_hash' => $hash,
                'state' => 'decided', 'band' => 'rejected', 'reasons' => '[]', 'feature_contributions' => '{}',
                'algorithm_version' => $algorithm, 'resolver_version' => 'test', 'normalizer_version' => 'test',
                'scorer_version' => 'test', 'policy_version' => 'test', 'supersedes_id' => $supersedes,
            ]);
        }

        return $ids;
    }

    /** @return array{DELETE_RULE: string, UPDATE_RULE: string} */
    private function supersedesRules(): array
    {
        $rules = DB::selectOne(
            'SELECT DELETE_RULE, UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['release_music_identifications', 'FK_rmi_supersedes'],
        );

        return ['DELETE_RULE' => (string) $rules->DELETE_RULE, 'UPDATE_RULE' => (string) $rules->UPDATE_RULE];
    }

    /** The confirmation token is encrypted, and the test environment's APP_KEY is not a usable cipher key. */
    private function useACipherKey(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    /**
     * One release from the poster in each of twelve invented groups, whose pattern takes 425 characters.
     *
     * @return list<string>
     */
    private function releasesInTwelveGroups(string $poster): array
    {
        $groups = [];
        for ($index = 1; $index <= 12; $index++) {
            $groups[] = $group = sprintf('alt.binaries.invented.shelf-%02d', $index);
            DB::table('usenet_groups')->insert(['id' => $index, 'name' => $group]);
            DB::table('releases')->insert([
                'guid' => str_pad((string) $index, 40, '0', STR_PAD_LEFT),
                'searchname' => 'Invented wide release '.$index,
                'fromname' => $poster,
                'groups_id' => $index,
            ]);
        }

        return $groups;
    }
}
