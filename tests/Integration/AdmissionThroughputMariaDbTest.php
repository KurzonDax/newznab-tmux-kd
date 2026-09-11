<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Console\Commands\ProcessReleasesCommand;
use App\Facades\Search;
use App\Models\Settings;
use App\Services\Binaries\BinariesConfig;
use App\Services\CollectionCleanupService;
use App\Services\CollectionReconciliation\CollectionAdmission;
use App\Services\CollectionReconciliation\CollectionOwnership;
use App\Services\CollectionReconciliation\PopulationQuery;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\CollectionArticleRangeMeasurer;
use Carbon\Carbon;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\RootCategoriesTableSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Termwind\Termwind;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\Reconciliation\AdmissionMariaDbFixture;
use Tests\Support\Reconciliation\AdmissionPhaseOutput;
use Tests\Support\Reconciliation\AdmissionTelemetry;
use Tests\Support\Reconciliation\AdmissionWindowReference;
use Tests\TestCase;

final class AdmissionThroughputMariaDbTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        if (getenv('CBP_INTEGRATION_DB_DATABASE') !== 'cbp_integration') {
            throw new \RuntimeException('Set CBP_INTEGRATION_DB_DATABASE=cbp_integration to run the development admission gate.');
        }
        config(['database.default' => 'mariadb', 'database.connections.mariadb.host' => 'mariadb',
            'database.connections.mariadb.database' => 'cbp_integration',
            'database.connections.mariadb.username' => 'sail', 'database.connections.mariadb.password' => 'password']);
        DB::purge('mariadb');
        AdmissionMariaDbFixture::requireIsolatedDatabase();
        Http::preventStrayRequests();
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        Search::shouldReceive('matchPre')->zeroOrMoreTimes()->andReturn(false);
        Search::shouldReceive('deleteRelease')->zeroOrMoreTimes();
        Schema::dropAllTables();
        DB::unprepared(file_get_contents(database_path('schema/mariadb-schema.sql')));
        // Undo dump backports whose migrations are still recorded as pending.
        (require database_path('migrations/2026_08_16_142055_add_name_trust_to_releases_table.php'))->down();
        (require database_path('migrations/2026_08_16_155110_add_srrdb_name_fixing_support.php'))->down();
        (require database_path('migrations/2026_08_25_124728_add_predb_search_lifecycle_to_predb_table.php'))->down();
        (require database_path('migrations/2026_08_25_162248_add_tv_episode_revisit_state_to_releases_table.php'))->down();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());
        $this->travelTo(Carbon::parse('2026-01-01 12:00:00', 'UTC'));
        DB::statement('SET timestamp = 1767268800');
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('admission-nzbs').DIRECTORY_SEPARATOR]);
        foreach (['delaytime' => '2', 'collection_timeout' => '48', 'maxnzbsprocessed' => '100',
            'minsizetoformrelease' => '2048', 'minfilestoformrelease' => '2', 'maxsizetoformrelease' => '0',
            'categorizeforeign' => '0', 'catwebdl' => '0', 'nzbsplitlevel' => '1', 'partretentionhours' => '72'] as $name => $value) {
            DB::table('settings')->updateOrInsert(['name' => $name], ['value' => $value]);
        }
        (new RootCategoriesTableSeeder)->run();
        (new CategoriesTableSeeder)->run();
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.synthetic', 'active' => 1,
            'last_record_postdate' => '2026-01-01 12:00:00']);
        DB::table('release_naming_regexes')->insert(['group_regex' => '.*',
            'regex' => '/^(?P<name>Synthetic\\.Series\\.S01E[0-9]+\\.1080p\\.TEST)$/', 'status' => 1, 'ordinal' => 0]);
        Cache::flush();
        Settings::forgetCachedSettings();
        $this->app->forgetInstance(ReleaseProcessingService::class);
        Artisan::registerCommand(new ProcessReleasesCommand(app(ReleaseProcessingService::class)->setEchoCLI(false)));
        $this->assertSame(100, app(ReleaseProcessingService::class)->getReleaseCreationLimit());
    }

    protected function tearDown(): void
    {
        try {
            if (DB::getDefaultConnection() === 'mariadb') {
                AdmissionMariaDbFixture::requireIsolatedDatabase();
                DB::statement('SET timestamp = 0');
                Schema::dropAllTables();
                DB::disconnect('mariadb');
            }
        } finally {
            $this->tearDownIsolatedDatabase();
            parent::tearDown();
        }
    }

    public function test_completeness_aggregates_preserve_inventory_with_tenfold_unrelated_parts_growth(): void
    {
        DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.binaries.background']);
        AdmissionMariaDbFixture::collections(1, 8, 1);
        DB::table('collections')->where('groups_id', 1)->update(['declaredfiles' => 1]);
        DB::table('parts')->where('binaries_id', 3)->delete();
        DB::table('binaries')->where('id', 5)->update(['totalparts' => 2]);
        DB::table('parts')->insert(['binaries_id' => 7, 'messageid' => 'extra@example.invalid',
            'number' => 99, 'partnumber' => 2, 'size' => 11]);
        DB::table('binaries')->where('collections_id', 4)->delete();
        $active = false;
        $before = 0;
        $reads = [];
        $plans = [];
        DB::connection()->beforeExecuting(function (string $sql) use (&$active, &$before): void {
            if ($active && str_starts_with($sql, 'UPDATE binaries ')) {
                $before = AdmissionTelemetry::handlerReads();
            }
        });
        DB::listen(function (QueryExecuted $event) use (&$active, &$before, &$reads, &$plans): void {
            if ($active && str_starts_with($event->sql, 'UPDATE binaries ')) {
                $reads[] = AdmissionTelemetry::handlerReads() - $before;
                $plans[] = DB::select('EXPLAIN '.$event->sql, $event->bindings);
            }
        });
        foreach ([1000, 10000] as $background) {
            $first = $background === 1000 ? 1001 : 2001;
            AdmissionMariaDbFixture::collections($first, $background === 1000 ? 1000 : 9000, 1, 2);
            DB::table('collections')->where('groups_id', 1)->update(['filecheck' => 2, 'filesize' => 99]);
            DB::table('binaries')->where('collections_id', '<=', 8)
                ->update(['currentparts' => 99, 'partsize' => 99, 'partcheck' => 0]);
            $active = true;
            try {
                app(ReleaseProcessingService::class)->processIncompleteCollections(1);
            } finally {
                $active = false;
            }
            foreach (DB::table('binaries')->where('collections_id', '<=', 8)->get() as $binary) {
                $expected = match ((int) $binary->id) {
                    3 => [0, 0, 0],
                    5 => [1, 1048576, 0],
                    7 => [2, 1048587, 1],
                    default => [1, 1048576, 1],
                };
                $this->assertSame($expected, [(int) $binary->currentparts, (int) $binary->partsize, (int) $binary->partcheck]);
            }
            $this->assertSame([1048576, 2097152, 2097163, 0, 2097152, 2097152, 2097152, 2097152],
                DB::table('collections')->where('groups_id', 1)->orderBy('id')->pluck('filesize')->map(intval(...))->all());
            $this->assertSame($background * 2, DB::table('binaries')->where('collections_id', '>', 1000)
                ->where('currentparts', 1)->where('partsize', 1048576)->where('partcheck', 1)->count());
        }
        fwrite(STDERR, 'COMPLETENESS_AGGREGATE='.json_encode(['handler_reads' => $reads, 'plans' => $plans], JSON_THROW_ON_ERROR).PHP_EOL);
        $this->assertCount(2, $reads);
        $this->assertLessThanOrEqual(2 * $reads[0] + 1000, $reads[1]);
        foreach ($reads as $count) {
            $this->assertLessThan(1000, $count);
        }
        foreach ($plans as $plan) {
            $parts = collect($plan)->first(static fn ($row): bool => $row->table === 'p' && $row->select_type === 'DERIVED');
            $this->assertNotNull($parts);
            $this->assertSame('ref', $parts->type);
            $this->assertSame('PRIMARY', $parts->key);
        }
    }

    #[Group('admission-scale')]
    public function test_all_valid_group_worker_publishes_one_thousand_named_nzbs(): void
    {
        AdmissionMariaDbFixture::collections(1, 1000, 1);
        for ($cycle = 0; $cycle < 40 && DB::table('releases')->where('nzbstatus', 1)->count() < 1000; $cycle++) {
            $worker = $this->runGroupWorker();
            fwrite(STDERR, 'ADMISSION_WORKER='.json_encode(['fixture' => 'all-valid'] + $worker, JSON_THROW_ON_ERROR).PHP_EOL);
            $this->assertSame(0, $worker['exit'], Artisan::output());
            $this->assertLessThan(1800, $worker['seconds']);
        }
        app(ReleaseProcessingService::class)->setEchoCLI(false)->deleteCollections(1);
        $this->assertSame(1000, DB::table('releases')->where('nzbstatus', 1)->where('searchname', 'like', 'Synthetic.Series.S01E%.1080p.TEST')->count());
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertNamedNzbs(1000);
    }

    public function test_default_limit_publishes_before_completeness_exhaustion_and_resumes_past_five_hundred(): void
    {
        DB::table('settings')->where('name', 'maxnzbsprocessed')->update(['value' => '1000']);
        Settings::forgetCachedSettings();
        $this->app->forgetInstance(ReleaseProcessingService::class);
        Artisan::registerCommand(new ProcessReleasesCommand(app(ReleaseProcessingService::class)->setEchoCLI(false)));
        AdmissionMariaDbFixture::collections(1, 1001, 1);
        $remainingAtFirstPublication = null;
        DB::listen(function (QueryExecuted $event) use (&$remainingAtFirstPublication): void {
            if ($remainingAtFirstPublication === null && str_starts_with(strtolower($event->sql), 'update `releases`')
                && str_contains($event->sql, '`nzbstatus`')
                && DB::table('releases')->where('nzbstatus', 1)->exists()) {
                $remainingAtFirstPublication = DB::table('collections')->where('filecheck', 2)->count();
            }
        });
        $this->assertSame(0, Artisan::call('releases:process', ['groupId' => 1, '--orchestrated' => true]));
        $this->assertNotNull($remainingAtFirstPublication);
        $this->assertGreaterThan(500, $remainingAtFirstPublication,
            'The first real named NZB must be published while the rest of the group still awaits sizing.');
        for ($cycle = 0; $cycle < 20 && DB::table('releases')->where('nzbstatus', 1)->count() < 1001; $cycle++) {
            $this->assertSame(0, Artisan::call('releases:process', ['groupId' => 1, '--orchestrated' => true]));
        }
        $this->assertSame(1001, DB::table('releases')->where('nzbstatus', 1)->count());
        $this->assertNamedNzbs(1001);
    }

    public function test_worker_reports_real_progress_and_exact_nzb_contents(): void
    {
        AdmissionMariaDbFixture::collections(1, 3, 1);
        $worker = $this->runGroupWorker();
        $this->assertSame(0, $worker['exit']);
        $this->assertNamedNzbs(3);
    }

    public function test_selected_sizing_and_quality_filters_do_not_scan_unrelated_sources(): void
    {
        AdmissionMariaDbFixture::collections(1, 1000, 1);
        DB::table('collections')->whereBetween('id', [1, 8])->update(['filecheck' => 3, 'filesize' => 2097152]);
        $active = false;
        $before = 0;
        $reads = [];
        DB::connection()->beforeExecuting(function (string $sql) use (&$active, &$before): void {
            if ($active && (str_contains($sql, 'having COUNT(b.id)')
                || str_starts_with($sql, 'select `id` from `collections`'))) {
                $before = AdmissionTelemetry::handlerReads();
            }
        });
        DB::listen(function (QueryExecuted $event) use (&$active, &$before, &$reads): void {
            if ($active && (str_contains($event->sql, 'having COUNT(b.id)')
                || str_starts_with($event->sql, 'select `id` from `collections`'))) {
                $reads[] = AdmissionTelemetry::handlerReads() - $before;
            }
        });
        foreach ([1000, 100000] as $population) {
            if ($population === 100000) {
                AdmissionMariaDbFixture::collections(1001, 99000, 1);
            }
            DB::table('collections')->whereBetween('id', [1, 8])->update(['filecheck' => 2]);
            $active = true;
            try {
                app(ReleaseProcessingService::class)->setEchoCLI(false)->processCollectionSizes(1, range(1, 8));
                app(ReleaseProcessingService::class)->setEchoCLI(false)->deleteUnwantedCollections(1, range(1, 8));
            } finally {
                $active = false;
            }
            $this->assertSame($population, DB::table('collections')->count());
            $this->assertSame(8, DB::table('collections')->where('filecheck', 3)->count());
        }
        $this->assertGreaterThanOrEqual(4, count($reads));
        foreach ($reads as $count) {
            $this->assertLessThan(500, $count, 'Eight selected sources must bound selection and the binary-side PAR2 check.');
        }
    }

    #[Group('admission-scale')]
    public function test_entire_worker_drains_one_hundred_thousand_sources_with_concurrent_arrivals(): void
    {
        AdmissionMariaDbFixture::collections(1, 100000);
        AdmissionMariaDbFixture::collections(200001, 2, 1);
        DB::table('collections')->where('id', 200002)->update(['filecheck' => 0, 'totalfiles' => 0,
            'last_seen_at' => '2026-01-01 11:59:00', 'last_seen_head_postdate' => '2026-01-01 11:59:00']);
        DB::table('reconciliation_admissions')->insert(['collection_id' => 200001, 'decision_id' => 'protected',
            'revision' => 'protected', 'admitted_at' => now(), 'expires_at' => now()->addHours(2), 'state' => 'admitted']);
        DB::table('reconciliation_claims')->insert(['collection_id' => 200001, 'owner' => 'synthetic-owner',
            'revision' => 'protected', 'deadline' => now()->addHours(2), 'lease_until' => now()->addHours(2)]);
        DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.binaries.unrelated', 'active' => 1,
            'last_record_postdate' => '2026-01-01 12:00:00']);
        AdmissionMariaDbFixture::collections(300001, 10, 1, 2);
        $protected = $this->protectedTrees();
        config(['database.connections.admission_writer' => config('database.connections.mariadb')]);
        $peer = DB::connection('admission_writer');
        $peer->statement('SET SESSION innodb_lock_wait_timeout=2');
        $commits = 0;
        $bursts = 0;
        $active = true;
        app('events')->listen(TransactionCommitted::class, function ($event) use (&$commits, &$bursts, &$active, $peer): void {
            if (! $active || $event->connection->getName() !== 'mariadb' || $event->connection->transactionLevel() !== 0) {
                return;
            }
            $commits++;
            if (! in_array($commits, [100, 1000, 5000], true)) {
                return;
            }
            $first = 100001 + $bursts * 1000;
            config(['database.default' => 'admission_writer']);
            try {
                $peer->transaction(static function () use ($first): void {
                    CollectionOwnership::ingest(range($first, $first + 999));
                    AdmissionMariaDbFixture::collections($first, 1000);
                });
                $bursts++;
                fwrite(STDERR, 'ADMISSION_ARRIVAL='.json_encode(['commit' => $commits, 'burst' => $bursts, 'rows' => 1000], JSON_THROW_ON_ERROR).PHP_EOL);
            } finally {
                config(['database.default' => 'mariadb']);
            }
        });
        $cycles = [];
        try {
            for ($cycle = 0; $cycle < 200; $cycle++) {
                $worker = $this->runGroupWorker();
                $remaining = DB::table('collections')->where('id', '<=', 103000)->count();
                $cycles[] = $worker + ['cycle' => $cycle + 1, 'remaining_before_finalization' => $remaining,
                    'expected_releases' => 1030, 'observed_releases' => DB::table('releases')->where('nzbstatus', 1)->count()];
                fwrite(STDERR, 'ADMISSION_WORKER='.json_encode(end($cycles), JSON_THROW_ON_ERROR).PHP_EOL);
                $this->assertSame(0, $worker['exit'], Artisan::output());
                $this->assertLessThan(1800, $worker['seconds']);
                $this->assertLessThan(1.0, $worker['transaction_p95_seconds']);
                app(ReleaseProcessingService::class)->setEchoCLI(false)->deleteCollections(1);
                $remaining = DB::table('collections')->where('id', '<=', 103000)->count();
                if ($cycle === 0) {
                    $this->assertLessThan(100000, $remaining);
                }
                if ($remaining === 0) {
                    break;
                }
            }
        } finally {
            $active = false;
            DB::disconnect('admission_writer');
        }
        $this->assertSame(3, $bursts);
        $this->assertEquals($protected, $this->protectedTrees());
        $this->assertSame(0, DB::table('collections')->where('id', '<=', 103000)->count());
        $this->assertSame(0, DB::table('binaries')->where('collections_id', '<=', 103000)->count());
        $this->assertSame(0, DB::table('parts')->where('binaries_id', '<=', 206002)->count());
        $this->assertSame(1030, DB::table('releases')->where('nzbstatus', 1)->where('searchname', 'like', 'Synthetic.Series.S01E%.1080p.TEST')->count());
        $this->assertSame(1030, DB::table('releases')->distinct()->count('collectionhash'));
        $this->assertNamedNzbs(1030);
    }

    /** @return array<string, mixed> */
    private function runGroupWorker(): array
    {
        $output = new AdmissionPhaseOutput;
        $previous = Termwind::getRenderer();
        \Termwind\renderUsing($output);
        app(ReleaseProcessingService::class)->setEchoCLI(true);
        $telemetry = new AdmissionTelemetry(explainAggregates: true);
        $previousSignals = pcntl_async_signals(true);
        $previousAlarm = pcntl_signal_get_handler(SIGALRM);
        pcntl_signal(SIGALRM, static function (): never {
            throw new \RuntimeException('The development worker exceeded its unchanged 1800-second budget.');
        });
        pcntl_alarm(1800);
        try {
            $exit = Artisan::call('releases:process', ['groupId' => 1, '--orchestrated' => true], $output);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousAlarm);
            pcntl_async_signals($previousSignals);
            \Termwind\renderUsing($previous);
            app(ReleaseProcessingService::class)->setEchoCLI(false);
        }
        $metrics = $telemetry->finish();
        $phases = $output->finish();
        if ($exit === 0) {
            foreach (['formation'] as $phase) {
                $this->assertArrayHasKey($phase, $phases);
            }
        }

        return $metrics + ['exit' => $exit, 'phase_seconds' => $phases];
    }

    /** @return array<string, Collection> */
    private function protectedTrees(): array
    {
        return [
            'collections' => DB::table('collections')->where('id', '>', 200000)->orderBy('id')->get(),
            'binaries' => DB::table('binaries')->where('collections_id', '>', 200000)->orderBy('id')->get(),
            'parts' => DB::table('parts')->where('binaries_id', '>', 400000)->orderBy('binaries_id')->orderBy('partnumber')->get(),
            'groups' => DB::table('collection_groups')->where('collections_id', '>', 200000)->orderBy('collections_id')->get(),
        ];
    }

    private function assertNamedNzbs(int $expected): void
    {
        $this->assertSame($expected, DB::table('releases')->count());
        foreach (DB::table('releases')->cursor() as $release) {
            $this->assertMatchesRegularExpression('/^Synthetic\\.Series\\.S01E([0-9]{6})\\.1080p\\.TEST$/', $release->searchname);
            preg_match('/S01E([0-9]{6})/', $release->searchname, $matches);
            $id = (int) $matches[1];
            $this->assertSame(sha1('admission-fixture:'.$id, true), $release->collectionhash);
            $this->assertSame(2097152, (int) $release->size);
            $this->assertSame(100.0, (float) $release->completion);
            $path = app(NzbService::class)->nzbPath($release->guid);
            $this->assertNotFalse($path);
            $xml = simplexml_load_string(gzdecode(file_get_contents($path)));
            $this->assertNotFalse($xml);
            $files = $xml->xpath('//*[local-name()="file"]');
            $this->assertCount(2, $files);
            foreach ($files as $index => $file) {
                $ordinal = $index + 1;
                $this->assertStringContainsString(sprintf('Synthetic.Series.S01E%06d.file%d.mkv', $id, $ordinal), (string) $file['subject']);
                $segments = $file->xpath('.//*[local-name()="segment"]');
                $this->assertCount(1, $segments);
                $this->assertSame('synthetic-'.$id.'-'.$ordinal.'@example.invalid', (string) $segments[0]);
                $this->assertSame('1048576', (string) $segments[0]['bytes']);
                $this->assertSame('1', (string) $segments[0]['number']);
            }
        }
    }

    #[DataProvider('newOwners')]
    public function test_peer_ownership_acquired_after_selection_survives_locked_cleanup(string $kind, int $declaration): void
    {
        AdmissionMariaDbFixture::collections(200001, 1, 1);
        DB::table('collections')->where('id', 200001)->update(['declaredfiles' => $declaration]);
        $before = $this->protectedTrees();
        config(['database.connections.ownership_peer' => config('database.connections.mariadb')]);
        $peer = DB::connection('ownership_peer');
        $peer->statement('SET SESSION innodb_lock_wait_timeout=1');
        $acquired = false;
        $active = true;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, Connection $connection) use ($peer, $kind, &$active, &$acquired): void {
            if (! $active || $acquired || $connection->getName() !== 'mariadb'
                || ! str_starts_with($sql, 'select * from `collections`')
                || ! str_contains($sql, '`id` in') || ! str_ends_with($sql, 'for update')) {
                return;
            }
            $this->assertSame([200001], $bindings);
            $this->assertSame(1, DB::transactionLevel());
            config(['database.default' => 'ownership_peer']);
            try {
                $peer->transaction(function () use ($kind): void {
                    $this->assertSame([200001], CollectionOwnership::ingest([200001]));
                    $this->seedPeerOwner($kind);
                });
                $acquired = true;
            } finally {
                config(['database.default' => 'mariadb']);
            }
        });
        try {
            $this->assertSame(0, app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([200001]));
            $this->assertTrue($acquired, 'The independent ownership writer must commit after selection and before the source lock.');
            $this->assertEquals($before, $this->protectedTrees());
        } finally {
            $active = false;
            DB::disconnect('ownership_peer');
        }
    }

    public static function newOwners(): iterable
    {
        foreach (['claim', 'recovery', 'artifact'] as $kind) {
            foreach ([1, 3] as $declaration) {
                yield $kind.'-'.$declaration => [$kind, $declaration];
            }
        }
    }

    private function seedPeerOwner(string $kind): void
    {
        if ($kind === 'claim') {
            DB::table('reconciliation_admissions')->insert(['collection_id' => 200001, 'decision_id' => 'peer-owned',
                'revision' => 'peer-owned', 'admitted_at' => now(), 'expires_at' => now()->addHour(), 'state' => 'admitted']);
            DB::table('reconciliation_claims')->insert(['collection_id' => 200001, 'owner' => 'peer-owner',
                'revision' => 'peer-owned', 'deadline' => now()->addHour(), 'lease_until' => now()->addHour()]);
        } elseif ($kind === 'recovery') {
            DB::table('obfuscation_recovery_publications')->insert(['identity' => str_repeat('a', 64),
                'index_identity' => str_repeat('b', 64), 'index_message_id' => 'owner@example.invalid',
                'set_id' => str_repeat('c', 32), 'plan_digest' => str_repeat('d', 64),
                'collection_projection' => sha1('peer-projection', true), 'collections_id' => 200001,
                'profile' => 'synthetic', 'group_name' => 'alt.binaries.synthetic', 'source_epoch' => 'synthetic',
                'state' => 'prepared', 'ordering_mode' => 'synthetic', 'inventory_scope' => 'synthetic',
                'protected_files' => 2, 'planned_files' => 2, 'planned_parts' => 2, 'sealed_plan' => '{}',
                'manifest_digest' => str_repeat('e', 64)]);
        } else {
            DB::table('releases')->insert(['id' => 999, 'guid' => 'synthetic-owner', 'leftguid' => 's']);
            DB::table('reconciled_artifact_operations')->insert(['id' => 'synthetic-reservation', 'release_id' => 999,
                'guid' => 'synthetic-owner', 'kind' => 'duplicate', 'expected_version' => 1, 'expected_epoch' => 1,
                'expected_proof_revision' => 1, 'target_digest' => str_repeat('a', 64), 'change_kind' => 'additive',
                'delta' => '{}', 'updates' => '{}', 'source_revisions' => '{}', 'state' => 'prepared']);
            DB::table('reconciled_artifact_sources')->insert(['operation_id' => 'synthetic-reservation',
                'collection_id' => 200001, 'revision' => str_repeat('b', 64), 'cleanup_pending' => true]);
        }
    }

    public function test_mixed_source_proofs_preserve_disjoint_windows_and_collation_identity(): void
    {
        AdmissionMariaDbFixture::collections(1, 8, 1);
        $changes = [
            2 => ['date' => '2026-01-01 11:30:00'],
            3 => ['groups_id' => 2],
            4 => ['fromname' => 'Different Poster'],
            5 => ['declaredfiles' => 3],
            6 => ['fromname' => 'synthetic poster'],
            7 => ['declaredfiles' => 0],
            8 => ['declaredfiles' => 1],
        ];
        foreach ($changes as $id => $values) {
            DB::table('collections')->where('id', $id)->update($values);
        }
        $this->assertSame(7, DB::table('collections')->where('fromname', 'Synthetic Poster')->count());
        $queries = new PopulationQuery;
        $sources = DB::table('collections')->orderBy('id')->get();
        foreach ([false, true] as $lock) {
            DB::transaction(function () use ($queries, $sources, $lock): void {
                $actual = $queries->admissionPopulations($lock ? $queries->lockIds(range(1, 8)) : $sources, $lock);
                $this->assertSame(range(1, 6), array_keys($actual));
                foreach ($sources->take(6) as $source) {
                    $expected = AdmissionWindowReference::read($queries->sourceWindow($source));
                    $this->assertEquals($expected, $actual[$source->id]);
                }
                $this->assertSame([1, 6], $actual[1]['rows']->pluck('id')->map(static fn ($id): int => (int) $id)->all());
                $this->assertSame([2], $actual[2]['rows']->pluck('id')->map(static fn ($id): int => (int) $id)->all());
            });
        }
    }

    #[DataProvider('witnessChanges')]
    public function test_current_overflow_proofs_revalidate_witness_changes_and_hold_locks_until_commit(string $change): void
    {
        AdmissionMariaDbFixture::collections(1, 257);
        DB::table('collections')->update(['declaredfiles' => 3]);
        DB::table('binaries')->where('collections_id', '<=', 8)->update(['name' => DB::raw("REPLACE(name, '/02]', '/03]')")]);
        $queries = new PopulationQuery;
        $ids = range(1, 8);
        $sources = DB::table('collections')->whereIn('id', $ids)->orderBy('id')->get();
        $this->assertFalse($queries->admissionPopulations($sources, false)[1]['complete']);
        config(['database.connections.admission_peer' => config('database.connections.mariadb')]);
        $peer = DB::connection('admission_peer');
        $peer->statement('SET SESSION innodb_lock_wait_timeout=1');
        $witness = (array) $peer->table('collections')->where('id', 257)->first();
        try {
            DB::beginTransaction();
            $this->assertSame(257, DB::table('collections')->count());
            $peer->transaction(static function () use ($peer, $change): void {
                $query = $peer->table('collections')->where('id', 257);
                $query->lockForUpdate()->first();
                match ($change) {
                    'delete' => $query->delete(),
                    'state' => $query->update(['filecheck' => 4]),
                    'date' => $query->update(['date' => '2026-01-01 11:00:00']),
                };
            });
            $locked = $queries->admissionPopulations($queries->lockIds($ids), true);
            $this->assertTrue($locked[1]['complete']);
            $this->assertCount(256, $locked[1]['rows']);
            $this->assertFalse($locked[1]['rows']->contains('id', 257));
            DB::commit();
            $peer->table('collections')->updateOrInsert(['id' => 257], $witness);

            DB::beginTransaction();
            $this->assertTrue(app(CollectionAdmission::class)->lockAndScreen($ids, 2));
            foreach (['delete', 'state', 'date'] as $heldChange) {
                try {
                    $query = $peer->table('collections')->where('id', 257);
                    match ($heldChange) {
                        'delete' => $query->delete(),
                        'state' => $query->update(['filecheck' => 4]),
                        'date' => $query->update(['date' => '2026-01-01 11:00:00']),
                    };
                    $this->fail('A held overflow witness changed before the mutation committed.');
                } catch (QueryException $exception) {
                    $this->assertSame(1205, (int) $exception->errorInfo[1]);
                }
            }
            $this->assertSame(10001, $peer->table('collections')->insertGetId([
                'id' => 10001, 'subject' => 'unrelated', 'groups_id' => 2, 'fromname' => 'Another Poster',
                'declaredfiles' => 3, 'collectionhash' => sha1('unrelated', true), 'date' => '2026-01-02 12:00:00',
            ]));
            DB::table('collections')->whereIn('id', $ids)->update(['filecheck' => 3]);
            DB::commit();
            $this->assertSame(1, $peer->table('collections')->where('id', 257)->update(['filecheck' => 4]));
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::disconnect('admission_peer');
        }
    }

    public static function witnessChanges(): array
    {
        return [['delete'], ['state'], ['date']];
    }

    public function test_bounded_callers_report_repeatable_development_benchmarks(): void
    {
        foreach (['dense', 'sparse', 'single', 'fallback', 'positive'] as $case) {
            foreach (['completeness', 'sizing', 'creation', 'cleanup'] as $caller) {
                $samples = [];
                for ($sample = 0; $sample < 6; $sample++) {
                    $ids = AdmissionMariaDbFixture::callerBatch($case);
                    if ($caller === 'creation') {
                        DB::table('collections')->whereIn('id', $ids)->update(['filecheck' => 3]);
                    }
                    try {
                        $metrics = $this->measureCallerWithPeer($caller, $ids);
                    } catch (\Throwable $exception) {
                        throw new \RuntimeException('Synthetic caller '.$case.'/'.$caller.': '.$exception->getMessage(), previous: $exception);
                    }
                    $this->assertSame(0, DB::transactionLevel());
                    $this->assertLessThan(1.0, $metrics['transaction_p95_seconds']);
                    if ($case === 'positive') {
                        $this->assertSame(2, DB::table('collections')->whereIn('id', $ids)->count());
                        $this->assertSame(2, DB::table('reconciliation_admissions')->where('state', 'admitted')->count());
                    } elseif ($caller === 'cleanup') {
                        $this->assertSame(0, DB::table('collections')->whereIn('id', $ids)->count());
                    } elseif ($caller === 'creation') {
                        $this->assertSame(count($ids), DB::table('releases')->count());
                    }
                    if ($sample > 0) {
                        $samples[] = $metrics;
                    }
                }
                fwrite(STDERR, 'ADMISSION_BENCHMARK='.json_encode(['case' => $case, 'caller' => $caller,
                    'reference' => getenv('ADMISSION_BENCHMARK_REFERENCE') === '1', 'samples' => $samples], JSON_THROW_ON_ERROR).PHP_EOL);
            }
        }
    }

    public function test_sparse_cleanup_allows_peer_ingestion(): void
    {
        $ids = AdmissionMariaDbFixture::callerBatch('sparse');
        $metrics = $this->measureCallerWithPeer('cleanup', $ids);
        $this->assertSame(1, $metrics['commits']);
    }

    public function test_positive_cleanup_admission_allows_peer_ingestion(): void
    {
        $ids = AdmissionMariaDbFixture::callerBatch('positive');
        $metrics = $this->measureCallerWithPeer('cleanup', $ids);
        $this->assertSame(1, $metrics['commits']);
        $this->assertSame(2, DB::table('collections')->whereIn('id', $ids)->count());
        $this->assertSame(2, DB::table('reconciliation_admissions')->where('state', 'admitted')->count());
    }

    /** @param list<int> $ids
     * @return array<string, int|float>
     */
    private function measureCallerWithPeer(string $caller, array $ids): array
    {
        AdmissionMariaDbFixture::collections(1900000, 1, 1, 2);
        /** Keep the unrelated writer beyond the next-key boundary and exact-ID reads selective on sparse fixtures. */
        $boundaryDeclaration = (int) DB::table('collections')->whereIn('id', $ids)->max('declaredfiles') + 1;
        DB::table('collections')->insert(['id' => 1800000, 'groups_id' => 1, 'declaredfiles' => $boundaryDeclaration,
            'fromname' => 'Synthetic boundary', 'date' => '2026-01-01 12:00:00',
            'dateadded' => '2026-01-01 12:00:00', 'last_seen_at' => '2026-01-01 12:00:00',
            'last_seen_head_postdate' => '2026-01-01 12:00:00', 'filecheck' => 0, 'totalfiles' => 0,
            'collectionhash' => sha1('synthetic-boundary', true)]);
        $boundary = (array) DB::table('collections')->where('id', 1800000)->first();
        for ($row = 1; $row <= 100; $row++) {
            DB::table('collections')->insert(array_replace($boundary, ['id' => 1800000 + $row,
                'collectionhash' => sha1('synthetic-boundary:'.$row, true)]));
        }
        $probe = DB::table('collections')->whereIn('id', array_slice($ids, 0, 8))->orderBy('id')->lockForUpdate()->select('id');
        $plan = DB::selectOne('EXPLAIN '.$probe->toSql(), $probe->getBindings());
        $this->assertSame('PRIMARY', $plan->key);
        $this->assertContains($plan->type, ['const', 'range']);
        config(['database.connections.benchmark_writer' => config('database.connections.mariadb')]);
        $peer = DB::connection('benchmark_writer');
        $peer->statement('SET SESSION innodb_lock_wait_timeout=1');
        $progress = 0;
        $active = true;
        app('events')->listen(TransactionCommitting::class, function ($event) use ($peer, &$active, &$progress): void {
            if (! $active || $progress > 0 || $event->connection->getName() !== 'mariadb') {
                return;
            }
            $this->assertSame(1, $event->connection->transactionLevel());
            config(['database.default' => 'benchmark_writer']);
            try {
                $peer->transaction(static function () use ($peer): void {
                    CollectionOwnership::ingest([1900000]);
                    $peer->table('collections')->where('id', 1900000)->update(['xref' => 'synthetic ingestion progressed']);
                });
                $progress++;
            } catch (\Throwable $exception) {
                fwrite(STDERR, 'ADMISSION_BARRIER_FAILURE='.json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
                throw new \RuntimeException('Synthetic ingestion barrier failed before the local commit: '.$exception->getMessage(), previous: $exception);
            } finally {
                config(['database.default' => 'mariadb']);
            }
        });
        $telemetry = new AdmissionTelemetry;
        try {
            $this->invokeCaller($caller, $ids);
        } finally {
            $active = false;
            DB::disconnect('benchmark_writer');
        }
        $metrics = $telemetry->finish() + ['ingestion_commits_while_local_locks_held' => $progress];
        $this->assertSame(1, $progress);

        return $metrics;
    }

    /** @param list<int> $ids */
    private function invokeCaller(string $caller, array $ids): void
    {
        match ($caller) {
            'completeness' => app(ReleaseProcessingService::class)->processIncompleteCollections(1),
            'sizing' => app(ReleaseProcessingService::class)->processCollectionSizes(1),
            'creation' => app(ReleaseCreationService::class)->createReleases(1, count($ids), false),
            'cleanup' => app(CollectionCleanupService::class)->deleteCollectionsAndDescendants($ids),
        };
    }

    public function test_discovery_remains_bounded_with_tenfold_matching_and_unrelated_growth(): void
    {
        $this->assertDiscoveryRemainsBounded(1000, 10000);
    }

    #[Group('admission-scale')]
    public function test_discovery_remains_bounded_at_one_million_background_collections(): void
    {
        $this->assertDiscoveryRemainsBounded(100000, 1000000);
    }

    public function test_nzb_stream_and_article_ranges_ignore_unrelated_parts_growth(): void
    {
        DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.binaries.background']);
        AdmissionMariaDbFixture::collections(1, 8, 1);
        DB::table('collections')->where('groups_id', 1)->update(['releases_id' => 1]);
        $firstName = DB::table('binaries')->where('id', 3)->value('name');
        DB::table('binaries')->where('id', 4)->update(['name' => $firstName]);
        DB::table('binaries')->where('id', 3)->update(['totalparts' => 2, 'currentparts' => 2]);
        DB::table('parts')->insert(['binaries_id' => 3, 'messageid' => 'extra-segment@example.invalid',
            'number' => 901, 'partnumber' => 2, 'size' => 7]);
        $writer = new NzbService(app(CollectionCleanupService::class), new BinariesConfig(nzbStreamRows: 3));
        $pageMethod = new \ReflectionMethod(NzbService::class, 'loadNzbRowPage');
        $metrics = [];
        foreach ([1000, 100000] as $background) {
            AdmissionMariaDbFixture::collections($background === 1000 ? 1001 : 2001,
                $background === 1000 ? 1000 : 99000, 1, 2);
            $before = AdmissionTelemetry::handlerReads();
            $cursor = ['collection_id' => 0, 'name' => '', 'binary_id' => 0, 'partnumber' => 0];
            $rows = [];
            $pages = 0;
            do {
                $page = $pageMethod->invoke($writer, 1, $cursor);
                foreach ($page as $row) {
                    $rows[] = [(int) $row->collection_id, (int) $row->binary_id, (int) $row->partnumber, $row->messageid];
                    $cursor = ['collection_id' => (int) $row->collection_id, 'name' => $row->binary_name,
                        'binary_id' => (int) $row->binary_id, 'partnumber' => (int) $row->partnumber];
                }
                $pages++;
            } while (count($page) === 3 && $pages < 10);
            $metrics[$background]['stream_reads'] = AdmissionTelemetry::handlerReads() - $before;
            $expectedRows = [];
            foreach (range(1, 8) as $source) {
                foreach ([1, 2] as $file) {
                    $binary = $source * 2 + $file;
                    $expectedRows[] = [$source, $binary, 1, 'synthetic-'.$source.'-'.$file.'@example.invalid'];
                    if ($binary === 3) {
                        $expectedRows[] = [$source, $binary, 2, 'extra-segment@example.invalid'];
                    }
                }
            }
            $this->assertSame($expectedRows, $rows);
            $this->assertSame(6, $pages);
            $before = AdmissionTelemetry::handlerReads();
            $ranges = app(CollectionArticleRangeMeasurer::class)->measure(range(1, 8));
            $metrics[$background]['range_reads'] = AdmissionTelemetry::handlerReads() - $before;
            foreach (range(1, 8) as $source) {
                $this->assertSame(['first' => $source * 2 + 1, 'last' => $source === 1 ? 901 : $source * 2 + 2], $ranges[$source]);
            }
            $this->assertLessThan(2000, $metrics[$background]['stream_reads']);
            $this->assertLessThan(500, $metrics[$background]['range_reads']);
        }
        foreach (['stream_reads', 'range_reads'] as $metric) {
            $this->assertLessThanOrEqual(2 * $metrics[1000][$metric] + 100, $metrics[100000][$metric]);
        }
        fwrite(STDERR, 'NZB_SCOPED_READS='.json_encode($metrics, JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function test_ineligible_source_inventory_reads_remain_bounded_as_matching_population_grows(): void
    {
        AdmissionMariaDbFixture::collections(1, 8, 1);
        DB::table('collections')->update(['declaredfiles' => 3]);
        DB::table('binaries')->update(['totalparts' => 3000, 'currentparts' => 3000,
            'name' => DB::raw("REPLACE(name, '/02]', '/03]')")]);
        $metrics = [];
        foreach ([1000, 100000] as $background) {
            AdmissionMariaDbFixture::collections($background === 1000 ? 1001 : 2001,
                $background === 1000 ? 1000 : 99000, 1);
            DB::table('collections')->where('id', '>', 8)->update(['declaredfiles' => 3]);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $before = AdmissionTelemetry::handlerReads();
            try {
                $this->assertTrue(DB::transaction(fn (): bool => app(CollectionAdmission::class)->lockAndScreen(range(1, 8), 2)));
                $metrics[$background] = AdmissionTelemetry::handlerReads() - $before;
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $this->assertLessThan(500, $metrics[$background]);
            $this->assertSame([], array_values(array_filter($queries,
                static fn (array $query): bool => str_contains($query['query'], '`date` between'))));
            $probes = array_values(array_filter($queries,
                static fn (array $query): bool => str_contains($query['query'], 'limit 1025')));
            $this->assertCount(2, $probes);
            foreach ($probes as $query) {
                $this->assertStringEndsWith('for update', $query['query']);
                $plan = DB::selectOne('EXPLAIN '.$query['query'], $query['bindings']);
                $this->assertContains($plan->type, ['ref', 'range']);
                $this->assertStringNotContainsString('filesort', (string) $plan->Extra);
                $this->assertContains($plan->key, ['ix_binaries_collection_filenumber', 'PRIMARY']);
            }
        }
        $this->assertLessThanOrEqual(2 * $metrics[1000] + 100, $metrics[100000]);
        $this->assertSame(0, DB::table('reconciliation_admissions')->count());
        fwrite(STDERR, 'SOURCE_INVENTORY_READS='.json_encode($metrics, JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function test_source_inventory_uses_current_reads_after_an_older_transaction_snapshot(): void
    {
        AdmissionMariaDbFixture::callerBatch('positive');
        DB::table('binaries')->where('id', 1)->update(['totalparts' => 2, 'currentparts' => 2]);
        config(['database.connections.inventory_peer' => config('database.connections.mariadb')]);
        $peer = DB::connection('inventory_peer');
        $peer->statement('SET SESSION innodb_lock_wait_timeout=1');
        try {
            DB::beginTransaction();
            $this->assertSame(5, DB::table('parts')->count());
            $peer->transaction(static function () use ($peer): void {
                $peer->table('collections')->where('id', 1)->lockForUpdate()->first();
                $peer->table('parts')->insert(['binaries_id' => 1, 'partnumber' => 2, 'number' => 99,
                    'messageid' => 'peer-segment@example.invalid', 'size' => 1048576]);
            });
            $this->assertSame(5, DB::table('parts')->count(), 'The ordinary read still sees the older repeatable-read snapshot.');
            $this->assertTrue(app(CollectionAdmission::class)->lockAndScreen([1], 2));
            $this->assertSame([1, 2], DB::table('reconciliation_admissions')->orderBy('collection_id')
                ->pluck('collection_id')->map(static fn ($id): int => (int) $id)->all());
            try {
                $peer->table('collections')->where('id', 1)->update(['xref' => 'peer mutation']);
                $this->fail('The source lock must survive inventory screening until the caller commits.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, (int) $exception->errorInfo[1]);
            }
            DB::commit();
            $this->assertSame(1, $peer->table('collections')->where('id', 1)->update(['xref' => 'peer mutation']));
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::disconnect('inventory_peer');
        }
    }

    private function assertDiscoveryRemainsBounded(int $smallPopulation, int $largePopulation): void
    {
        $metrics = [];
        foreach (['unrelated', 'matching'] as $kind) {
            foreach ([$smallPopulation, $largePopulation] as $background) {
                $ids = AdmissionMariaDbFixture::callerBatch('dense');
                DB::table('collections')->where('id', '>', 128)->update(['filecheck' => 2]);
                $group = $kind === 'matching' ? 1 : 2;
                DB::statement("INSERT INTO collections (id,subject,fromname,date,groups_id,declaredfiles,collectionhash,
                    dateadded,last_seen_at,last_seen_head_postdate,filecheck)
                    SELECT seq + 2000000, 'Synthetic background', 'Synthetic Poster', '2026-01-01 09:00:00', ?, 3,
                    UNHEX(SHA1(CONCAT('background:',seq))), '2026-01-01 12:00:00', '2026-01-01 12:00:00',
                    '2026-01-01 12:00:00', 2 FROM seq_1_to_{$background}", [$group]);
                foreach (['screen', 'lockAndScreen'] as $method) {
                    $telemetry = new AdmissionTelemetry;
                    DB::flushQueryLog();
                    DB::enableQueryLog();
                    try {
                        foreach (array_chunk($ids, 8) as $chunk) {
                            $operation = fn (): bool => app(CollectionAdmission::class)->$method($chunk, 2);
                            $this->assertTrue($method === 'screen' ? $operation() : DB::transaction($operation));
                        }
                        $queries = DB::getQueryLog();
                    } finally {
                        DB::disableQueryLog();
                        DB::flushQueryLog();
                    }
                    $result = $telemetry->finish();
                    $this->assertSame(16 * 257, $result['admission_rows']);
                    $this->assertLessThanOrEqual(16 * 7, $result['admission_queries']);
                    $this->assertLessThan(1.0, $result['transaction_p95_seconds']);
                    foreach ($queries as $query) {
                        if (! str_contains($query['query'], 'from `collections`')) {
                            continue;
                        }
                        if (str_contains($query['query'], '`date` between')) {
                            $this->assertStringStartsWith('select `id`', $query['query']);
                            $expectedIndex = 'collections_admission_window';
                        } elseif (str_contains($query['query'], 'for update')) {
                            $expectedIndex = 'PRIMARY';
                        } else {
                            continue;
                        }
                        $plan = DB::selectOne('EXPLAIN '.$query['query'], $query['bindings']);
                        $this->assertSame($expectedIndex, $plan->key);
                        $this->assertContains($plan->type, ['const', 'ref', 'range']);
                        $this->assertStringNotContainsString('filesort', (string) $plan->Extra);
                    }
                    $metrics[$kind][$background][$method] = $result;
                    fwrite(STDERR, 'ADMISSION_SCALE='.json_encode(['kind' => $kind, 'background' => $background,
                        'method' => $method] + $result, JSON_THROW_ON_ERROR).PHP_EOL);
                }
                DB::table('collections')->where('id', '>', 2000000)->update(['filecheck' => 0]);
                foreach (['completeness', 'sizing', 'creation', 'cleanup'] as $caller) {
                    $ids = AdmissionMariaDbFixture::callerBatch('dense', true);
                    if ($caller === 'creation') {
                        DB::table('collections')->whereIn('id', $ids)->update(['filecheck' => 3]);
                    }
                    $telemetry = new AdmissionTelemetry(true);
                    $this->invokeCaller($caller, $ids);
                    $result = $telemetry->finish();
                    $this->assertGreaterThan(0, $result['admission_handler_reads']);
                    $this->assertLessThanOrEqual(2 * 128 * 257, $result['admission_rows']);
                    $this->assertLessThan(1.0, $result['transaction_p95_seconds']);
                    $metrics[$kind][$background][$caller] = $result;
                    fwrite(STDERR, 'ADMISSION_SCALE='.json_encode(['kind' => $kind, 'background' => $background,
                        'method' => $caller] + $result, JSON_THROW_ON_ERROR).PHP_EOL);
                }
            }
            foreach (['completeness', 'sizing', 'creation', 'cleanup'] as $caller) {
                $this->assertLessThanOrEqual(2 * $metrics[$kind][$smallPopulation][$caller]['admission_handler_reads'] + 1000,
                    $metrics[$kind][$largePopulation][$caller]['admission_handler_reads']);
            }
            foreach (['screen', 'lockAndScreen'] as $method) {
                $this->assertLessThanOrEqual(2 * $metrics[$kind][$smallPopulation][$method]['handler_reads'] + 1000,
                    $metrics[$kind][$largePopulation][$method]['handler_reads']);
            }
        }
    }
}
