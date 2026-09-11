<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Release;
use App\Services\CollectionCleanupService;
use App\Services\CollectionReconciliation\ArtifactPublication;
use App\Services\CollectionReconciliation\CollectionAdmission;
use App\Services\CollectionReconciliation\CollectionClaims;
use App\Services\CollectionReconciliation\CollectionOwnership;
use App\Services\CollectionReconciliation\PendingInventory;
use App\Services\CollectionReconciliation\PendingReconciler;
use App\Services\CollectionReconciliation\PostingDecision;
use App\Services\CollectionReconciliation\PostingEvidence;
use App\Services\CollectionReconciliation\PostingNzb;
use App\Services\CollectionsCleaningService;
use App\Services\NNTP\Contracts\BoundedProviderClient;
use App\Services\NNTP\DTO\BoundedArticleResponse;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseProcessingService;
use App\Services\YencService;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Reconciliation\CreatesPostingSchema;
use Tests\Support\Reconciliation\Par2Fixture;
use Tests\TestCase;

class SplitAdmissionTest extends TestCase
{
    use CreatesPostingSchema;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        $this->registerSqliteFunction('regexp', static fn ($pattern, $value): int => preg_match('/'.str_replace('/', '\\/', $pattern).'/', (string) $value) === 1 ? 1 : 0, 2);
        $this->createPostingSchema();
        Cache::flush();
        (require database_path('migrations/2026_09_10_134847_add_reconciliation_admissions.php'))->up();
        (require database_path('migrations/2026_09_10_224820_create_collection_sweep_cursors_table.php'))->up();
        $this->travelTo(Carbon::parse('2026-01-01T12:00:00Z'));
        DB::table('collection_regexes')->insert(['group_regex' => '.*', 'regex' => '/^\[\d+\/\d+\] - "(?P<name>[^.]+)\./', 'status' => 1]);
        $this->source(1, [1 => 'Example.mkv', 4 => 'Example.par2', 5 => 'Example.vol000+001.par2']);
        $this->source(2, [2 => 'example.r10', 3 => 'example.sfv']);
    }

    public function test_sources_without_a_group_do_not_receive_admission(): void
    {
        DB::table('collections')->update(['groups_id' => 999]);
        $this->assertTrue(DB::transaction(fn (): bool => app(CollectionAdmission::class)->lockAndScreen([1, 2], 1)));
        $this->assertSame(0, DB::table('reconciliation_admissions')->count());
    }

    public function test_impossible_declarations_skip_speculative_population_and_inventory_reads(): void
    {
        foreach ([0, 1] as $declaration) {
            DB::table('collections')->update(['declaredfiles' => $declaration]);
            foreach (['screen', 'lockAndScreen'] as $method) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                try {
                    $this->assertTrue(DB::transaction(fn (): bool => app(CollectionAdmission::class)->$method([1, 2], 1)));
                    foreach (DB::getQueryLog() as $query) {
                        $this->assertStringNotContainsString('"date" between', $query['query']);
                        $this->assertStringNotContainsString('from "binaries"', $query['query']);
                        $this->assertStringNotContainsString('from "parts"', $query['query']);
                    }
                } finally {
                    DB::disableQueryLog();
                    DB::flushQueryLog();
                }
            }
        }
        $this->assertSame(0, DB::table('reconciliation_admissions')->count());
    }

    public function test_impossible_declarations_do_not_bypass_existing_ownership_during_ordinary_mutation(): void
    {
        DB::table('collections')->where('id', 1)->update(['declaredfiles' => 0]);
        DB::table('collections')->where('id', 2)->update(['declaredfiles' => 1]);
        foreach ([1, 2] as $id) {
            DB::table('reconciliation_admissions')->insert(['collection_id' => $id, 'decision_id' => 'protected-'.$id,
                'revision' => 'protected', 'admitted_at' => now(), 'expires_at' => now()->addHours(1), 'state' => 'admitted']);
        }
        $before = [DB::table('collections')->get(), DB::table('binaries')->get(), DB::table('parts')->get()];
        app(ReleaseProcessingService::class)->setEchoCLI(false)->processCollectionSizes(1);
        app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([1, 2]);
        $this->assertEquals($before, [DB::table('collections')->get(), DB::table('binaries')->get(), DB::table('parts')->get()]);
        $this->assertTrue(CollectionOwnership::protects(1));
        $this->assertTrue(CollectionOwnership::protects(2));
    }

    public static function denseStates(): array
    {
        return [[0], [1], [2], [3], [10], [15], [16], [null]];
    }

    #[DataProvider('denseStates')]
    public function test_dense_overlapping_batches_use_bounded_id_only_admission_proofs(?int $state): void
    {
        $template = (array) DB::table('collections')->where('id', 1)->first();
        DB::table('parts')->delete();
        DB::table('binaries')->delete();
        DB::table('collections')->delete();
        for ($id = 1; $id <= 385; $id++) {
            DB::table('collections')->insert(array_replace($template, [
                'id' => $id, 'collectionhash' => sha1('dense:'.$id, true), 'declaredfiles' => 3,
                'filecheck' => $state ?? ($id <= 250 ? 0 : 16), 'date' => Carbon::parse('2026-01-01 09:00:00')->addSeconds($id % 120),
                'subject' => str_repeat('s', 255), 'xref' => str_repeat('x', 2000),
            ]));
            if ($id <= 128) {
                $binary = DB::table('binaries')->insertGetId(['collections_id' => $id,
                    'name' => sprintf('[01/03] - "Example-%d.par2" yEnc', $id),
                    'totalparts' => 1, 'currentparts' => 1, 'partcheck' => 1, 'filenumber' => 1]);
                DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => 1,
                    'messageid' => 'dense-'.$id.'@example.invalid', 'size' => 100]);
            }
        }
        foreach (['screen', 'lockAndScreen'] as $method) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                if ($method === 'screen') {
                    $this->assertTrue(app(CollectionAdmission::class)->screen(range(1, 128), 1));
                } else {
                    foreach (array_chunk(range(1, 128), 8) as $ids) {
                        $this->assertTrue(DB::transaction(fn (): bool => app(CollectionAdmission::class)->lockAndScreen($ids, 1)));
                    }
                }
                $proofs = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], '"date" between'));
                $this->assertNotEmpty($proofs);
                $this->assertLessThanOrEqual(16 * 7, count($proofs));
                foreach ($proofs as $query) {
                    $this->assertStringStartsWith('select "id" from "collections"', $query['query']);
                }
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }
        $this->assertSame(0, DB::table('reconciliation_admissions')->count());
    }

    public function test_failed_intersection_falls_back_to_a_positive_family_beside_a_crowded_envelope(): void
    {
        DB::table('collections')->update(['date' => '2026-01-01 09:00:00']);
        $template = (array) DB::table('collections')->where('id', 1)->first();
        for ($id = 3; $id <= 260; $id++) {
            DB::table('collections')->insert(array_replace($template, ['id' => $id,
                'collectionhash' => sha1('neighbor:'.$id, true),
                'date' => $id === 3 ? '2026-01-01 10:59:00' : '2026-01-01 11:00:00']));
        }
        foreach (['screen', 'lockAndScreen'] as $method) {
            DB::table('reconciliation_admissions')->delete();
            $this->assertTrue(DB::transaction(fn (): bool => app(CollectionAdmission::class)->$method([1, 3], 1)));
            $this->assertSame([1, 2], DB::table('reconciliation_admissions')->orderBy('collection_id')->pluck('collection_id')->all());
        }
    }

    public function test_pending_discovery_counts_recovery_owned_rows_before_overflow(): void
    {
        Schema::create('obfuscation_recovery_publications', static function (Blueprint $table): void {
            $table->unsignedBigInteger('collections_id')->primary();
            $table->string('state');
        });
        $source = (array) DB::table('collections')->where('id', 1)->first();
        for ($id = 3; $id <= 257; $id++) {
            DB::table('collections')->insert(array_replace($source, ['id' => $id, 'collectionhash' => sha1('owned:'.$id, true)]));
            DB::table('obfuscation_recovery_publications')->insert(['collections_id' => $id, 'state' => 'prepared']);
        }
        $this->assertSame('source_population', app(PendingReconciler::class)->reconcile(1, 1));
        foreach (['screen', 'lockAndScreen'] as $method) {
            $this->assertTrue(DB::transaction(fn (): bool => app(CollectionAdmission::class)->$method([1, 2], 1)));
            $this->assertSame(0, DB::table('reconciliation_admissions')->where('state', 'admitted')->count());
        }
    }

    public function test_unlocked_screening_finds_the_source_population_in_the_application_timezone(): void
    {
        config(['app.timezone' => 'America/Chicago']);
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/Chicago');
        try {
            $this->travelTo(Carbon::parse('2026-01-01 12:00:00', 'America/Chicago'));
            $this->assertTrue(app(CollectionAdmission::class)->screen([1], 1));
            $this->assertSame(2, DB::table('reconciliation_admissions')->where('state', 'admitted')->count());
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function test_sizing_processes_an_entire_page_across_independent_small_commits(): void
    {
        $source = (array) DB::table('collections')->where('id', 1)->first();
        for ($id = 3; $id <= 502; $id++) {
            DB::table('collections')->insert(array_replace($source, ['id' => $id, 'fromname' => 'Poster-'.$id,
                'filecheck' => 2, 'collectionhash' => sha1((string) $id, true)]));
        }
        $completed = [];
        $this->app['events']->listen(TransactionCommitted::class,
            function ($event) use (&$completed): void {
                if ($event->connection->transactionLevel() === 0) {
                    $completed[] = DB::table('collections')->where('filecheck', 3)->count();
                }
            });
        $processing = app(ReleaseProcessingService::class);
        $processing->setEchoCLI(false);
        $processing->processCollectionSizes(1);
        $this->assertSame(500, DB::table('collections')->where('filecheck', 3)->count());
        $previous = 0;
        foreach ($completed as $count) {
            $this->assertLessThanOrEqual(8, $count - $previous);
            $previous = $count;
        }
        $this->assertSame(500, $previous);
    }

    public function test_unsupported_binary_inventory_stops_before_parts_aggregation(): void
    {
        $binary = (array) DB::table('binaries')->first();
        unset($binary['id']);
        for ($i = 0; $i < 1025; $i++) {
            DB::table('binaries')->insert($binary);
        }
        DB::enableQueryLog();
        try {
            $this->assertTrue(DB::transaction(fn (): bool => app(CollectionAdmission::class)->lockAndScreen([1], 1)));
            foreach (DB::getQueryLog() as $query) {
                $this->assertStringNotContainsString('from "parts"', $query['query']);
            }
            $this->assertNotEmpty(array_filter(DB::getQueryLog(),
                static fn (array $query): bool => str_contains($query['query'], '"date" between')));
            $this->assertSame(0, DB::table('reconciliation_admissions')->count());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_complete_source_windows_skip_parts_aggregation_and_cleaning(): void
    {
        DB::table('parts')->delete();
        DB::table('binaries')->delete();
        DB::table('collections')->delete();
        foreach (range(1, 204) as $id) {
            $this->source($id, [1 => 'Complete.mkv', 2 => 'Complete.r10', 3 => 'Complete.sfv',
                4 => 'Complete.par2', 5 => 'Complete.vol000+001.par2']);
        }
        $cleaning = \Mockery::mock(CollectionsCleaningService::class);
        $cleaning->shouldNotReceive('collectionsCleaner');
        $admission = new CollectionAdmission($cleaning);
        foreach (['screen', 'lockAndScreen'] as $method) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $this->assertTrue(DB::transaction(fn (): bool => $admission->$method([1, 2], 1)));
                foreach (DB::getQueryLog() as $query) {
                    $this->assertStringNotContainsString('from "parts"', $query['query']);
                }
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }
        $this->assertSame(0, DB::table('reconciliation_admissions')->count());
    }

    public static function ineligibleSourceInventory(): array
    {
        return [['missing_segments'], ['complete_files'], ['invalid_ordinal'], ['no_files']];
    }

    #[DataProvider('ineligibleSourceInventory')]
    public function test_locked_ineligible_sources_skip_neighbor_reads(string $case): void
    {
        $template = (array) DB::table('collections')->where('id', 1)->first();
        for ($id = 3; $id <= 385; $id++) {
            DB::table('collections')->insert(array_replace($template, ['id' => $id,
                'collectionhash' => sha1('ineligible-neighbor:'.$id, true)]));
        }
        match ($case) {
            'missing_segments' => DB::table('binaries')->update(['totalparts' => 3000, 'currentparts' => 3000]),
            'complete_files' => DB::table('collections')->whereIn('id', [1, 2])->update(['declaredfiles' => 2]),
            'invalid_ordinal' => DB::table('binaries')->update(['name' => '[06/05] - "Example.mkv" yEnc']),
            'no_files' => DB::table('binaries')->delete(),
        };
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->assertTrue(DB::transaction(fn (): bool => app(CollectionAdmission::class)->lockAndScreen([1, 2], 1)));
            $queries = DB::getQueryLog();
            $this->assertSame([], array_values(array_filter($queries,
                static fn (array $query): bool => str_contains($query['query'], '"date" between'))));
            $this->assertLessThan(20, count($queries));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame(0, DB::table('reconciliation_admissions')->count());
    }

    public function test_source_inventory_rejection_is_rechecked_in_the_next_transaction(): void
    {
        $binary = (int) DB::table('binaries')->where('collections_id', 1)->value('id');
        DB::table('binaries')->where('id', $binary)->update(['totalparts' => 2, 'currentparts' => 2]);
        $this->assertTrue(DB::transaction(fn (): bool => app(CollectionAdmission::class)->lockAndScreen([1], 1)));
        $this->assertSame(0, DB::table('reconciliation_admissions')->count());
        DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => 2,
            'messageid' => 'late-source-segment@example.invalid', 'size' => 100]);
        $this->assertTrue(DB::transaction(fn (): bool => app(CollectionAdmission::class)->lockAndScreen([1], 1)));
        $this->assertSame([1, 2], DB::table('reconciliation_admissions')->orderBy('collection_id')->pluck('collection_id')->all());
    }

    public function test_source_inventory_rejection_does_not_bypass_existing_ownership(): void
    {
        DB::table('binaries')->update(['totalparts' => 3000, 'currentparts' => 3000]);
        foreach ([1, 2] as $id) {
            DB::table('reconciliation_admissions')->insert(['collection_id' => $id, 'decision_id' => 'protected-inventory-'.$id,
                'revision' => 'protected', 'admitted_at' => now(), 'expires_at' => now()->addHours(1), 'state' => 'admitted']);
        }
        $before = [DB::table('collections')->get(), DB::table('binaries')->get(), DB::table('parts')->get()];
        $this->assertSame(0, app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([1, 2]));
        $this->assertEquals($before, [DB::table('collections')->get(), DB::table('binaries')->get(), DB::table('parts')->get()]);
        $this->assertTrue(CollectionOwnership::protects(1));
        $this->assertTrue(CollectionOwnership::protects(2));
    }

    public function test_neighbor_inventory_can_become_eligible_after_the_source_inventory_read(): void
    {
        $binary = (int) DB::table('binaries')->where('collections_id', 2)->value('id');
        DB::table('binaries')->where('id', $binary)->update(['totalparts' => 2, 'currentparts' => 2]);
        $arrived = false;
        DB::listen(static function (QueryExecuted $query) use (&$arrived, $binary): void {
            if (! $arrived && str_starts_with($query->sql, 'select "binaries_id", "partnumber" from "parts"')) {
                $arrived = true;
                DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => 2,
                    'messageid' => 'late-neighbor-segment@example.invalid', 'size' => 100]);
            }
        });
        $this->assertTrue(DB::transaction(fn (): bool => app(CollectionAdmission::class)->lockAndScreen([1], 1)));
        $this->assertTrue($arrived);
        $this->assertSame([1, 2], DB::table('reconciliation_admissions')->orderBy('collection_id')->pluck('collection_id')->all());
    }

    public function test_source_inventory_preselection_does_not_cache_a_quietness_rejection(): void
    {
        DB::table('collections')->update(['dateadded' => '2026-01-01 11:30:00']);
        $advanced = false;
        DB::listen(function (QueryExecuted $query) use (&$advanced): void {
            if (! $advanced && str_starts_with($query->sql, 'select "binaries_id", "partnumber" from "parts"')) {
                $advanced = true;
                $this->travel(2)->hours();
            }
        });
        $this->assertTrue(DB::transaction(fn (): bool => app(CollectionAdmission::class)->lockAndScreen([1], 1)));
        $this->assertTrue($advanced);
        $this->assertSame([1, 2], DB::table('reconciliation_admissions')->orderBy('collection_id')->pluck('collection_id')->all());
    }

    public static function largeSourceInventories(): array
    {
        return [[1025, false], [1025, true], [1, true]];
    }

    #[DataProvider('largeSourceInventories')]
    public function test_source_part_probe_overflow_preserves_the_original_population_path(int $declaredParts, bool $crowded): void
    {
        $binary = (int) DB::table('binaries')->where('collections_id', 1)->value('id');
        DB::table('binaries')->where('id', $binary)->update(['totalparts' => $declaredParts]);
        $parts = [];
        foreach (range(2, 1025) as $part) {
            $parts[] = ['binaries_id' => $binary, 'partnumber' => $part,
                'messageid' => 'large-source-'.$part.'@example.invalid', 'size' => 100];
        }
        DB::table('parts')->insert($parts);
        if ($crowded) {
            $template = (array) DB::table('collections')->where('id', 1)->first();
            for ($id = 3; $id <= 257; $id++) {
                DB::table('collections')->insert(array_replace($template, ['id' => $id,
                    'collectionhash' => sha1('large-inventory-neighbor:'.$id, true)]));
            }
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->assertTrue(DB::transaction(fn (): bool => app(CollectionAdmission::class)->lockAndScreen([1], 1)));
            $queries = DB::getQueryLog();
            $this->assertNotEmpty(array_filter($queries,
                static fn (array $query): bool => str_contains($query['query'], '"date" between')));
            $probes = array_values(array_filter($queries,
                static fn (array $query): bool => str_starts_with($query['query'], 'select "binaries_id", "partnumber" from "parts"')));
            $this->assertCount(1, $probes);
            $this->assertStringEndsWith('limit 1025', $probes[0]['query']);
            if ($crowded) {
                $this->assertSame([], array_values(array_filter($queries,
                    static fn (array $query): bool => str_contains($query['query'], 'COUNT(*) AS held'))));
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame($crowded ? [] : [1, 2], DB::table('reconciliation_admissions')->orderBy('collection_id')->pluck('collection_id')->all());
    }

    public function test_complete_neighbors_are_not_aggregated_while_valid_fragment_partners_remain_eligible(): void
    {
        $this->source(3, [1 => 'Complete.mkv', 2 => 'Complete.r10', 3 => 'Complete.sfv',
            4 => 'Complete.par2', 5 => 'Complete.vol000+001.par2']);
        $completeIds = DB::table('binaries')->where('collections_id', 3)->pluck('id')->all();
        $fragmentIds = DB::table('binaries')->whereIn('collections_id', [1, 2])->pluck('id')->all();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->assertSame([1, 2], app(CollectionAdmission::class)->eligibleIds([1, 2, 3], 1));
            $aggregates = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'COUNT(*) AS held'));
            $this->assertNotEmpty($aggregates);
            foreach ($aggregates as $query) {
                $this->assertSame([], array_values(array_intersect($completeIds, $query['bindings'])));
                $this->assertSame($fragmentIds, array_values(array_intersect($fragmentIds, $query['bindings'])));
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertTrue(DB::transaction(fn (): bool => app(CollectionAdmission::class)->lockAndScreen([1, 2], 1)));
        $this->assertSame([1, 2], DB::table('reconciliation_admissions')->orderBy('collection_id')->pluck('collection_id')->all());
    }

    public function test_complete_sources_still_count_toward_the_raw_binary_limit(): void
    {
        $this->source(3, [1 => 'Complete.mkv', 2 => 'Complete.r10', 3 => 'Complete.sfv',
            4 => 'Complete.par2', 5 => 'Complete.vol000+001.par2']);
        $binary = (array) DB::table('binaries')->where('collections_id', 3)->first();
        unset($binary['id']);
        DB::table('binaries')->insert(array_fill(0, 1015, $binary));

        $this->assertNull(app(CollectionAdmission::class)->eligibleIds([1, 2, 3], 1));
        $this->assertSame(0, DB::table('reconciliation_admissions')->count());
    }

    public function test_artifact_population_overflow_defers_then_can_retry_after_population_shrinks(): void
    {
        $source = (array) DB::table('collections')->where('id', 1)->first();
        for ($id = 3; $id <= 257; $id++) {
            DB::table('collections')->insert(array_replace($source, ['id' => $id, 'filecheck' => [0, 1, 2, 3, 10, 15, 16][$id % 7], 'collectionhash' => sha1((string) $id, true)]));
        }
        $window = ['group' => 1, 'poster' => 'Synthetic Poster', 'total' => 5,
            'from' => '2026-01-01 08:00:00', 'until' => '2026-01-01 10:00:00'];
        $publication = app(ArtifactPublication::class);
        $this->assertNull(DB::transaction(fn () => $publication->lockSources([1, 2], $window)));
        (require database_path('migrations/2026_09_10_140952_create_reconciled_artifact_operations.php'))->up();
        $association = new \ReflectionMethod(PendingReconciler::class, 'publishAssociation');
        $decision = new PostingDecision([], 5, null, 'Example', [], [], 'verified');
        $this->assertSame('source_population_incomplete', $association->invoke(app(PendingReconciler::class),
            [1, 2], 'overflow-worker', 'unchanged', $decision, $window, [1, 2]));
        $this->assertSame(0, DB::table('releases')->count());
        DB::table('collections')->where('id', 257)->delete();
        $this->assertCount(256, DB::transaction(fn () => $publication->lockSources([1, 2], $window)));
        DB::table('collections')->where('id', 256)->delete();
        $this->assertCount(255, DB::transaction(fn () => $publication->lockSources([1, 2], $window)));
    }

    public function test_locked_admission_never_combines_seed_ids_with_population_ranges(): void
    {
        DB::enableQueryLog();
        try {
            $this->assertTrue(DB::transaction(fn (): bool => app(CollectionAdmission::class)->lockAndScreen([1, 2], 1)));
            foreach (DB::getQueryLog() as $query) {
                if (str_starts_with($query['query'], 'select * from "collections"')) {
                    $this->assertStringNotContainsString(' or ', $query['query']);
                }
            }
            $this->assertSame(2, DB::table('reconciliation_admissions')->count());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_oversized_ordinary_admission_yields_without_creating_holds(): void
    {
        $this->assertFalse(DB::transaction(fn (): bool => app(CollectionAdmission::class)->lockAndScreen(range(1, 9), 1)));
        $this->assertSame(0, DB::table('reconciliation_admissions')->count());
    }

    public function test_artifact_reservation_fences_existing_evidence_claim_until_abandoned(): void
    {
        (require database_path('migrations/2026_09_10_140952_create_reconciled_artifact_operations.php'))->up();
        $files = app(PendingInventory::class)->load([1, 2]);
        $revision = PendingInventory::digest($files);
        $claims = app(CollectionClaims::class);
        $this->assertNotNull($claims->claim([1, 2], 'evidence-worker', $revision, 1));
        DB::table('releases')->insert(['id' => 99, 'guid' => 'reserved-artifact']);
        DB::table('reconciled_artifact_operations')->insert([
            'id' => 'source-reservation', 'release_id' => 99, 'guid' => 'reserved-artifact',
            'kind' => 'duplicate', 'expected_version' => 1, 'expected_epoch' => 1,
            'expected_proof_revision' => 1, 'target_digest' => str_repeat('a', 64),
            'change_kind' => 'additive', 'delta' => '{}', 'updates' => '{}',
            'source_revisions' => '{}', 'state' => 'prepared',
        ]);
        DB::table('reconciled_artifact_sources')->insert([
            'operation_id' => 'source-reservation', 'collection_id' => 1,
            'revision' => str_repeat('b', 64), 'cleanup_pending' => true,
        ]);
        $decision = new PostingDecision($files, 5, null, 'Example', [], [], 'verified');
        $this->assertFalse(app(CollectionAdmission::class)->admitProven($decision, 1));
        $publish = new \ReflectionMethod(PendingReconciler::class, 'publishAssociation');
        $population = ['group' => 1, 'poster' => 'Synthetic Poster', 'total' => 5,
            'from' => '2026-01-01 00:00:00', 'until' => '2026-01-02 00:00:00'];
        $this->assertSame('changed_inventory', $publish->invoke(app(PendingReconciler::class), [1, 2], 'evidence-worker', $revision, $decision, $population, [1, 2]));
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertNull($claims->claim([1, 2], 'next-worker', $revision, 1));
        DB::table('reconciled_artifact_operations')->update(['state' => 'abandoned']);
        $this->assertTrue(app(CollectionAdmission::class)->admitProven($decision, 1));
    }

    #[DataProvider('completeUnionCases')]
    public function test_exact_case_sensitive_five_file_fixture_requires_every_advertised_ordinal(bool $complete, bool $existingEmptyNzb = false): void
    {
        (require database_path('migrations/2026_09_10_140952_create_reconciled_artifact_operations.php'))->up();
        DB::table('collections')->update(['date' => '2026-01-01 12:00:00', 'last_seen_head_postdate' => '2026-01-01 12:00:00']);
        DB::table('usenet_groups')->update(['name' => 'example.group', 'last_record_postdate' => '2026-01-01 14:00:00']);
        if (! $complete) {
            $binary = DB::table('binaries')->where('filenumber', 3)->value('id');
            DB::table('parts')->where('binaries_id', $binary)->delete();
            DB::table('binaries')->where('id', $binary)->delete();
        }
        $manifest = Par2Fixture::metadata(['Example.mkv' => 'video', 'example.r10' => 'archive', 'example.sfv' => 'checksum']);
        $responses = [];
        foreach ((new PendingInventory)->load([1, 2]) as $file) {
            $id = $file->firstArticle();
            $responses['HEAD'.$id] = "From: Synthetic Poster\r\nSubject: {$file->subject} (1/1) 100\r\nDate: Thu, 01 Jan 2026 12:00:00 +0000\r\nMessage-ID: {$id}\r\n";
            if ($file->isPar2()) {
                $responses['BODY'.$id] = (new YencService)->encode($manifest, $file->filename);
            }
        }
        $client = \Mockery::mock(BoundedProviderClient::class);
        $client->shouldReceive('fetchBoundedArticle')->andReturnUsing(static function ($id, $head) use ($responses) {
            $data = $responses[($head ? 'HEAD' : 'BODY').$id];

            return new BoundedArticleResponse($data, strlen($data));
        });
        $provider = new NntpProvider(1, 'fixture', 'example.invalid', 119, false, '', '', 1, 5, true);
        $this->app->instance(PostingEvidence::class, new PostingEvidence(new NntpProviderPool([$provider], clientFactory: static fn () => $client), new YencService));
        $reason = app(PendingReconciler::class)->reconcile(1, 1);
        $this->assertSame($complete ? 'associated' : 'verified_incomplete', $reason);
        if (! $complete) {
            $this->assertSame(0, DB::table('releases')->count());
            $this->assertTrue(CollectionOwnership::protects(1));
            $this->assertTrue(CollectionOwnership::protects(2));

            return;
        }
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('five-file-union')]);
        $release = Release::query()->first();
        $nzbs = app(NzbService::class);
        if ($existingEmptyNzb) {
            $path = $nzbs->getNzbPath($release->guid, $nzbs->getNzbSplitLevel(), true);
            $existing = gzencode('');
            file_put_contents($path, $existing);
            $this->assertFalse($nzbs->readNzbContents($release->guid));
            $this->assertFalse($nzbs->createNzbForRelease($release)->success);
            $this->assertSame($existing, file_get_contents($path));
            $this->assertSame('conflict', DB::table('reconciled_artifact_operations')->value('state'));

            return;
        }
        $result = $nzbs->createNzbForRelease($release);
        $this->assertTrue($result->success, $result->reason);
        $files = (new PostingNzb)->parse($nzbs->readNzbContents($release->guid), 'published');
        $this->assertCount(5, $files);
        $this->assertContains('Example.mkv', array_column($files, 'filename'));
        $this->assertContains('example.r10', array_column($files, 'filename'));
        $this->assertSame(100.0, (float) $release->fresh()->completion);
    }

    public static function completeUnionCases(): iterable
    {
        yield 'all five files' => [true];
        yield 'existing unusable NZB is never absence' => [true, true];
        yield 'four files at eighty percent' => [false];
    }

    public function test_mutation_rescreens_a_previously_ineligible_source_under_its_locks(): void
    {
        DB::table('binaries')->where('collections_id', 2)->where('filenumber', 2)->update(['totalparts' => 2]);
        $this->assertTrue(app(CollectionAdmission::class)->screen([1, 2], 1));
        $this->assertFalse(CollectionOwnership::protects(1));
        DB::transaction(function (): void {
            CollectionOwnership::ingest([2]);
            DB::table('binaries')->where('collections_id', 2)->where('filenumber', 2)->update(['totalparts' => 1]);
        });
        $this->assertSame(0, app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([1, 2]));
        $this->assertTrue(CollectionOwnership::protects(1));
        $this->assertTrue(CollectionOwnership::protects(2));
    }

    public function test_disproval_releases_the_relationship_and_ingestion_does_not_extend_its_deadline(): void
    {
        $admission = app(CollectionAdmission::class);
        $admission->screen([1, 2], 1);
        $deadline = DB::table('reconciliation_admissions')->where('collection_id', 1)->value('expires_at');
        $admission->disprove([2]);
        $this->assertFalse(CollectionOwnership::protects(1));
        $this->assertFalse(CollectionOwnership::protects(2));
        $admission->screen([1, 2], 1);
        $this->assertFalse(CollectionOwnership::protects(1));
        $this->travel(60)->seconds();
        DB::transaction(static fn () => CollectionOwnership::ingest([1, 2]));
        $admission->screen([1, 2], 1);
        $this->assertTrue(CollectionOwnership::protects(1));
        $this->assertSame($deadline, DB::table('reconciliation_admissions')->where('collection_id', 1)->value('expires_at'));
    }

    public function test_family_admission_protects_whole_sources_and_expires_without_renewal(): void
    {
        app(CollectionAdmission::class)->screen([1, 2], 1);
        $this->assertTrue(CollectionOwnership::protects(1));
        $this->assertTrue(CollectionOwnership::protects(2));
        $this->assertSame(0, app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([1, 2]));
        $this->travel(7199)->seconds();
        app(CollectionAdmission::class)->screen([1, 2], 1);
        $this->assertTrue(CollectionOwnership::protects(1));
        $this->travel(1)->seconds();
        app(CollectionAdmission::class)->screen([1, 2], 1);
        $this->assertFalse(CollectionOwnership::protects(1));
        app(CollectionAdmission::class)->screen([1, 2], 1);
        $this->assertFalse(CollectionOwnership::protects(2));
        $this->assertSame(2, app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([1, 2]));
    }

    public function test_sizing_and_cleanup_admit_sources_without_a_worker_pass(): void
    {
        DB::table('collections')->update(['filecheck' => 2, 'filesize' => 100]);
        $processing = app(ReleaseProcessingService::class);
        $processing->setEchoCLI(false);
        $processing->processCollectionSizes(1);
        $this->assertTrue(CollectionOwnership::protects(1));
        $this->assertSame(2, (int) DB::table('collections')->where('id', 1)->value('filecheck'));
        DB::table('reconciliation_admissions')->delete();
        $this->assertSame(0, app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([1, 2]));
        $this->assertTrue(CollectionOwnership::protects(2));
    }

    public static function boundedMutationAdmissionCases(): array
    {
        return [
            'cleanup existing family' => [false, false],
            'creation existing family' => [true, false],
            'cleanup partner completed at mutation' => [false, true],
            'creation partner completed at mutation' => [true, true],
        ];
    }

    #[DataProvider('boundedMutationAdmissionCases')]
    public function test_bounded_mutation_proves_and_admits_the_current_family_only_under_lock(bool $create, bool $latePart): void
    {
        DB::table('collections')->update(['filecheck' => 3, 'filesize' => 100]);
        $arrived = false;
        if ($latePart) {
            $binary = DB::table('binaries')->where('collections_id', 2)->orderBy('id')->value('id');
            $part = (array) DB::table('parts')->where('binaries_id', $binary)->first();
            DB::table('parts')->where('id', $part['id'])->delete();
            $this->app['events']->listen(TransactionBeginning::class, static function () use ($part, &$arrived): void {
                if (! $arrived) {
                    $arrived = true;
                    DB::table('parts')->insert($part);
                }
            });
        }
        $populationReadLevels = [];
        DB::listen(static function (QueryExecuted $query) use (&$populationReadLevels): void {
            if (str_contains($query->sql, '"date" between')) {
                $populationReadLevels[] = $query->connection->transactionLevel();
            }
        });

        if ($create) {
            $this->assertSame(['added' => 0, 'dupes' => 0], app(ReleaseCreationService::class)->createSelectedCollections(1, [1], false));
        } else {
            $this->assertSame(0, app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([1]));
        }

        $this->assertSame($latePart, $arrived);
        $this->assertNotEmpty($populationReadLevels);
        $this->assertNotContains(0, $populationReadLevels, 'Explicit bounded mutations must not repeat the locked population proof as an unlocked preflight.');
        $this->assertSame([1, 2], DB::table('reconciliation_admissions')->where('state', 'admitted')->orderBy('collection_id')->pluck('collection_id')->all());
        $this->assertSame([3, 3], DB::table('collections')->orderBy('id')->pluck('filecheck')->all());
        $this->assertSame(5, DB::table('parts')->count());
        $this->assertSame(0, DB::table('releases')->count());
    }

    public function test_candidate_cap_preserves_the_pair_and_allows_ordinary_progress(): void
    {
        DB::table('collections')->where('id', 1)->update(['id' => 101, 'collectionhash' => sha1('101', true)]);
        DB::table('collections')->where('id', 2)->update(['id' => 102]);
        DB::table('binaries')->where('collections_id', 1)->update(['collections_id' => 101]);
        DB::table('binaries')->where('collections_id', 2)->update(['collections_id' => 102]);
        $this->source(1, [1 => 'Unrelated.txt']);
        DB::table('collections')->update(['last_seen_head_postdate' => '2026-01-01 09:00:00']);
        DB::table('usenet_groups')->where('id', 1)->update(['active' => 1, 'last_record_postdate' => '2026-01-01 12:00:00']);
        config(['collection-reconciliation.candidate_limit' => 1]);
        $this->app->instance(PostingEvidence::class, new PostingEvidence(new NntpProviderPool([]), new YencService));
        $processing = app(ReleaseProcessingService::class);
        $processing->setEchoCLI(false);
        $processing->processIncompleteCollections(1);
        $this->assertFalse(CollectionOwnership::protects(1), 'Worker leases alone must not hold unsupported ordinary sources.');
        $this->assertTrue(CollectionOwnership::protects(101), 'The pair must receive its cheap admission screen.');
        $this->assertSame(2, (int) DB::table('collections')->where('id', 1)->value('filecheck'));
        $this->assertTrue(CollectionOwnership::protects(101));
        $this->assertTrue(CollectionOwnership::protects(102));
    }

    public function test_shared_deferrals_do_not_spend_transport_retries_or_renew_admission(): void
    {
        app(CollectionAdmission::class)->screen([1, 2], 1);
        $expires = DB::table('reconciliation_admissions')->where('collection_id', 1)->value('expires_at');
        $revision = PendingInventory::digest(app(PendingInventory::class)->load([1, 2]));
        $claims = app(CollectionClaims::class);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->assertNotNull($claims->claim([1, 2], 'worker', $revision, 1));
            $claims->retry('worker', 'budget_exhausted');
        }
        $this->assertSame(0, (int) DB::table('reconciliation_claims')->where('collection_id', 1)->value('attempts'));
        $this->assertSame($expires, DB::table('reconciliation_admissions')->where('collection_id', 1)->value('expires_at'));
        $this->assertTrue(CollectionOwnership::protects(1));
    }

    public function test_transport_exhaustion_stops_reads_but_retains_admitted_hold(): void
    {
        app(CollectionAdmission::class)->screen([1, 2], 1);
        $revision = PendingInventory::digest(app(PendingInventory::class)->load([1, 2]));
        $claims = app(CollectionClaims::class);
        foreach ([60, 300, 300] as $delay) {
            $this->assertNotNull($claims->claim([1, 2], 'worker', $revision, 1));
            $claims->retry('worker', 'article_unavailable');
            $this->assertNull($claims->claim([1, 2], 'worker', $revision, 1));
            $this->travel($delay)->seconds();
        }
        $this->assertNull($claims->claim([1, 2], 'worker', $revision, 1));
        $this->assertSame(3, (int) DB::table('reconciliation_claims')->where('collection_id', 1)->value('attempts'));
        $this->assertTrue(CollectionOwnership::protects(1));
    }

    #[DataProvider('ineligibleCases')]
    public function test_ineligible_pairs_receive_no_speculative_hold(string $case): void
    {
        match ($case) {
            'gap' => DB::table('parts')->where('binaries_id', 1)->delete(),
            'poster' => DB::table('collections')->where('id', 2)->update(['fromname' => 'Different Poster']),
            'count' => DB::table('collections')->where('id', 2)->update(['declaredfiles' => 6]),
            'time' => DB::table('collections')->where('id', 2)->update(['date' => '2026-01-01 10:30:01']),
            'family' => DB::table('binaries')->where('collections_id', 2)->update(['name' => '[02/05] - "Unrelated.r10" yEnc']),
            'overlap' => DB::table('binaries')->where('collections_id', 2)->where('filenumber', 2)->update(['name' => '[01/05] - "example.r10" yEnc']),
            'out-of-range' => DB::table('binaries')->where('collections_id', 2)->where('filenumber', 2)->update(['name' => '[06/05] - "example.r10" yEnc']),
            'not-quiet' => DB::table('collections')->where('id', 2)->update(['last_seen_head_postdate' => '2026-01-01 12:00:00']),
        };
        app(CollectionAdmission::class)->screen([1, 2], 1);
        $this->assertFalse(CollectionOwnership::protects(1));
        $this->assertFalse(CollectionOwnership::protects(2));
    }

    /** @return iterable<string, array{string}> */
    public static function ineligibleCases(): iterable
    {
        foreach (['gap', 'poster', 'count', 'time', 'family', 'overlap', 'out-of-range', 'not-quiet'] as $case) {
            yield $case => [$case];
        }
    }

    /** @param array<int, string> $files */
    private function source(int $id, array $files): void
    {
        DB::table('collections')->insert(['id' => $id, 'groups_id' => 1, 'fromname' => 'Synthetic Poster',
            'subject' => reset($files), 'date' => '2026-01-01 10:00:00', 'dateadded' => '2026-01-01 09:00:00',
            'totalfiles' => 5, 'declaredfiles' => 5, 'collectionhash' => sha1((string) $id, true)]);
        foreach ($files as $ordinal => $name) {
            $binary = DB::table('binaries')->insertGetId(['collections_id' => $id,
                'name' => sprintf('[%02d/05] - "%s" yEnc', $ordinal, $name),
                'totalparts' => 1, 'currentparts' => 1, 'partcheck' => 1, 'filenumber' => $ordinal]);
            DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => 1,
                'messageid' => 'file-'.$ordinal.'@example.invalid', 'size' => 100]);
        }
    }
}
