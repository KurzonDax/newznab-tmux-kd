<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\CollectionCleanupService;
use App\Services\CollectionReconciliation\ArtifactInventory;
use App\Services\CollectionReconciliation\BundleIdentity;
use App\Services\CollectionReconciliation\CollectionOwnership;
use App\Services\CollectionReconciliation\HistoricalReconciliation;
use App\Services\CollectionReconciliation\PendingInventory;
use App\Services\CollectionReconciliation\PendingReconciler;
use App\Services\CollectionReconciliation\PostingEvidence;
use App\Services\CollectionReconciliation\PostingNzb;
use App\Services\CollectionReconciliation\PostingPublication;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NNTP\Contracts\BoundedProviderClient;
use App\Services\NNTP\Contracts\ProviderClient;
use App\Services\NNTP\DTO\BoundedArticleResponse;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseProcessingService;
use App\Services\ReleaseRepair\MissingFileRescanOptions;
use App\Services\ReleaseRepair\MissingFileRescanService;
use App\Services\ReleaseRepair\ReleaseRepairOptions;
use App\Services\ReleaseRepair\ReleaseRepairService;
use App\Services\ReleaseRepair\RescanRunBudget;
use App\Services\ReleaseRepair\RescanWindowResolver;
use App\Services\YencService;
use Database\Seeders\CollectionRegexesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Reconciliation\CreatesPostingSchema;
use Tests\Support\Reconciliation\FakeHeaderNntp;
use Tests\Support\Reconciliation\Par2Fixture;
use Tests\Support\TestBinariesHarness;
use Tests\TestCase;

class PendingReconciliationTest extends TestCase
{
    use CreatesPostingSchema;

    private array $courseHeaders = [];

    private ?\Closure $beforeFirstRead = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        $this->registerSqliteFunction('regexp', static fn ($pattern, $value): int => preg_match('/'.str_replace('/', '\\/', $pattern).'/i', (string) $value) === 1 ? 1 : 0, 2);
        $this->createPostingSchema();
        NzbCreationCandidateQuery::flushCapabilityCache();
        $this->seed(CollectionRegexesTableSeeder::class);
        Cache::flush();
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        $this->travelTo(now()->setDate(2026, 1, 1)->setTime(12, 0));
    }

    protected function tearDown(): void
    {
        NzbCreationCandidateQuery::flushCapabilityCache();
        parent::tearDown();
    }

    public function test_candidate_cap_yields_to_formation_in_the_same_cycle(): void
    {
        config(['collection-reconciliation.candidate_limit' => 2, 'collection-reconciliation.cycle_seconds' => 30]);
        $this->seedBoundedCandidates();
        $decisions = 0;
        Log::listen(static function ($event) use (&$decisions): void {
            if ($event->message === 'Collection reconciliation decision') {
                $decisions++;
            }
        });
        $processing = app(ReleaseProcessingService::class);
        $processing->setEchoCLI(false);
        $processing->processIncompleteCollections(1);

        $this->assertSame(2, $decisions);
        $this->assertSame(2, (int) DB::table('collections')->where('id', 4)->value('filecheck'));
    }

    public function test_time_budget_reports_an_early_stop(): void
    {
        config(['collection-reconciliation.candidate_limit' => 10, 'collection-reconciliation.cycle_seconds' => 1]);
        $this->seedBoundedCandidates();
        $clock = 0.0;
        $service = new PendingReconciler(app(PendingInventory::class), app(PostingEvidence::class),
            static function () use (&$clock): float {
                return $clock;
            });
        Log::listen(static function ($event) use (&$clock): void {
            if ($event->message === 'Collection reconciliation decision') {
                $clock = 2.0;
            }
        });
        $result = $service->run(1, 1);
        $this->assertSame(['processed' => 1, 'stopped_early' => true], $result);
    }

    public function test_capped_cycles_make_progress_past_unchanged_candidates(): void
    {
        config(['collection-reconciliation.candidate_limit' => 2]);
        $this->seedBoundedCandidates();
        $ids = [];
        Log::listen(static function ($event) use (&$ids): void {
            if ($event->message === 'Collection reconciliation decision') {
                $ids[] = $event->context['collection_id'];
            }
        });
        $service = app(PendingReconciler::class);
        $service->run(1, 1);
        $service->run(1, 1);
        $this->assertSame([1, 2, 3, 4], $ids);
    }

    public function test_foreign_group_publications_do_not_consume_pending_budget(): void
    {
        config(['collection-reconciliation.candidate_limit' => 2]);
        $this->seedBoundedCandidates();
        foreach ([10, 11, 12] as $id) {
            DB::table('releases')->insert(['id' => $id, 'groups_id' => 2]);
            DB::table('reconciled_postings')->insert(['release_id' => $id, 'state' => 'created',
                'digest' => (string) $id, 'inventory' => '[]', 'decision' => '{}', 'budget_id' => (string) $id,
                'original_nzb' => 'pending']);
        }
        $ids = [];
        Log::listen(static function ($event) use (&$ids): void {
            if ($event->message === 'Collection reconciliation decision') {
                $ids[] = $event->context['collection_id'];
            }
        });
        app(PendingReconciler::class)->run(1, 1);
        $this->assertSame([1, 2], $ids);
    }

    public function test_unclaimable_publication_cannot_monopolize_single_candidate_cycles(): void
    {
        config(['collection-reconciliation.candidate_limit' => 1]);
        $this->seedBoundedCandidates();
        DB::table('releases')->insert(['id' => 10, 'groups_id' => 1,
            'recovery_claimed_at' => now(), 'recovery_claim_token' => 'another-worker']);
        DB::table('reconciled_postings')->insert(['release_id' => 10, 'state' => 'created',
            'digest' => 'busy', 'inventory' => '[]', 'decision' => '{}', 'budget_id' => 'busy', 'original_nzb' => 'pending']);
        $ids = [];
        Log::listen(static function ($event) use (&$ids): void {
            if ($event->message === 'Collection reconciliation decision') {
                $ids[] = $event->context['collection_id'];
            }
        });
        $service = app(PendingReconciler::class);
        $service->run(1, 1);
        $service->run(1, 1);
        $this->assertSame([1], $ids);
    }

    private function seedBoundedCandidates(): void
    {
        DB::table('settings')->insert(['name' => 'delaytime', 'value' => '1']);
        foreach ([1, 2, 3, 4] as $id) {
            DB::table('collections')->insert(['id' => $id, 'groups_id' => 1, 'subject' => 'Fixture', 'fromname' => 'fixture@example.invalid',
                'declaredfiles' => 2, 'totalfiles' => $id === 4 ? 1 : 2, 'filecheck' => 0,
                'date' => '2026-01-01 10:00:00', 'dateadded' => '2026-01-01 10:00:00',
                'added' => '2026-01-01 10:00:00', 'last_seen_at' => '2026-01-01 10:00:00',
                'last_seen_head_postdate' => '2026-01-01 10:00:00']);
        }
        DB::table('binaries')->insert(['id' => 4, 'collections_id' => 4, 'name' => 'Fixture',
            'totalparts' => 1, 'currentparts' => 1, 'partcheck' => 1, 'partsize' => 100]);
        DB::table('parts')->insert(['binaries_id' => 4, 'number' => 1, 'messageid' => 'fixture@example.invalid', 'partnumber' => 1, 'size' => 100]);
    }

    public function test_ingested_course_fragments_are_associated_once_after_the_frontier_is_quiet(): void
    {
        (require database_path('migrations/2026_09_10_134847_add_reconciliation_admissions.php'))->up();
        (require database_path('migrations/2026_09_10_140952_create_reconciled_artifact_operations.php'))->up();
        [$service, $id] = $this->ingestCourse();
        $this->assertSame('not_quiet', $service->reconcile($id, 1));
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-01-01 14:00:00']);
        $this->assertSame('associated', $service->reconcile($id, 1));
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(1, DB::table('collections')->distinct()->count('releases_id'));
        $this->assertSame(100.0, (float) DB::table('releases')->value('completion'));
        $this->assertSame('Course.Set', DB::table('releases')->value('searchname'));
        $this->assertSame(0, (int) DB::table('releases')->value('is_trusted_name'));
        $this->assertSame('not_pending', $service->reconcile($id, 1));
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('reconciliation-nzbs')]);
        $nzbs = app(NzbService::class);
        $release = Release::query()->first();
        $result = $nzbs->createNzbForRelease($release);
        $this->assertTrue($result->success, $result->reason);
        $xml = $nzbs->readNzbContents($release->guid);
        $this->assertCount(33, (new PostingNzb)->parse($xml, 'stored'));
        $this->assertSame(1, (int) DB::table('reconciled_artifacts')->value('version'));
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(100.0, (float) $release->fresh()->completion);
        $this->assertTrue($nzbs->createNzbForRelease($release->fresh())->success);
        (new TestBinariesHarness)->simulateScan($this->courseHeaders, ['id' => 1, 'name' => 'alt.binaries.boneless']);
        $replayIds = DB::table('collections')->pluck('id')->all();
        foreach ($replayIds as $replayId) {
            $this->assertSame('replayed', $service->reconcile((int) $replayId, 1));
        }
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame($xml, $nzbs->readNzbContents($release->guid));

    }

    #[DataProvider('ordinaryWriterCases')]
    public function test_late_addition_preserves_current_replacement_and_unproved_opaque_file(bool $repair): void
    {
        (require database_path('migrations/2026_09_10_134847_add_reconciliation_admissions.php'))->up();
        (require database_path('migrations/2026_09_10_140952_create_reconciled_artifact_operations.php'))->up();
        [$service, $id] = $this->ingestCourse(multipart: $repair);
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-01-01 14:00:00']);
        $this->assertSame('associated', $service->reconcile($id, 1));
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('current-artifact-late')]);
        $nzbs = app(NzbService::class);
        $release = Release::query()->first();
        $this->assertTrue($nzbs->createNzbForRelease($release)->success);
        $document = new \DOMDocument;
        $document->loadXML($nzbs->readNzbContents($release->guid));
        foreach (iterator_to_array($document->getElementsByTagName('file')) as $file) {
            if (str_contains($file->getAttribute('subject'), 'bundle.r15')) {
                $file->parentNode->removeChild($file);
            }
            if ($repair && str_contains($file->getAttribute('subject'), 'bundle.r10')) {
                $segments = $file->getElementsByTagName('segments')->item(0);
                foreach (iterator_to_array($segments->getElementsByTagName('segment')) as $segment) {
                    if ($segment->getAttribute('number') === '1') {
                        $segments->removeChild($segment);
                    }
                }
            }
        }
        $this->assertTrue($nzbs->replaceNzbContents($release->guid, $document->saveXML())->success);
        if ($repair) {
            Schema::table('releases', function (Blueprint $table): void {
                $table->timestamp('repair_attempted_at')->nullable();
                $table->string('repair_outcome')->nullable();
                $table->double('repair_target_completion')->nullable();
                $table->double('repair_evaluated_target_completion')->nullable();
                foreach (['pp_timeout_count', 'proc_nfo', 'proc_files', 'proc_srr', 'proc_crc32', 'proc_uid', 'proc_hash16k', 'proc_par2', 'proc_srrdb', 'proc_xxx', 'proc_media_movie'] as $column) {
                    $table->integer($column)->default(1);
                }
            });
            DB::statement('CREATE TABLE video_data (releases_id INTEGER PRIMARY KEY)');
            DB::statement('CREATE TABLE audio_data (id INTEGER PRIMARY KEY, releases_id INTEGER)');
            $client = Mockery::mock(ProviderClient::class);
            $client->shouldReceive('doConnect')->andReturn(true);
            $client->shouldReceive('statArticle')->once()->with('part1of3.CourseRepair@host')->andReturn(true);
            $client->shouldReceive('doQuit')->andReturn(true);
            $provider = new NntpProvider(1, 'repair-fixture', 'example.invalid', 119, false, '', '', 1, 5, true);
            $pool = new NntpProviderPool([$provider], clientFactory: static fn () => $client);
            $result = (new ReleaseRepairService($nzbs, $pool))->repair($release->fresh(), new ReleaseRepairOptions);
            $this->assertSame(1, $result->segmentsAdded, $result->reason);
            $document->loadXML($nzbs->readNzbContents($release->guid));
            $this->assertStringContainsString('part1of3.CourseRepair@host', $document->saveXML());
            $this->assertSame('additive', DB::table('reconciled_artifact_operations')->where('kind', 'repair')->value('change_kind'));
        }
        $opaque = $document->createElement('file');
        $opaque->setAttribute('subject', '"unproved.txt" (1/1)');
        $opaque->setAttribute('poster', 'Unrelated Poster');
        $opaque->setAttribute('date', '1767268800');
        $opaque->appendChild($document->createElement('groups'))->appendChild($document->createElement('group', 'alt.binaries.boneless'));
        $segment = $opaque->appendChild($document->createElement('segments'))->appendChild($document->createElement('segment', 'opaque@example.invalid'));
        $segment->setAttribute('bytes', '7');
        $segment->setAttribute('number', '1');
        $opaque->appendChild($document->createElement('unrecognized', 'keep this metadata'));
        $document->documentElement->appendChild($opaque);
        $this->assertTrue($nzbs->replaceNzbContents($release->guid, $document->saveXML())->success);
        $late = array_values(array_filter($this->courseHeaders, static fn ($header): bool => str_contains($header['Subject'], '"bundle.r15"')));
        (new TestBinariesHarness)->simulateScan($late, ['id' => 1, 'name' => 'alt.binaries.boneless']);
        $lateId = (int) DB::table('collections')->value('id');
        $this->assertSame('late_added', $service->reconcile($lateId, 1));
        $stored = $nzbs->readNzbContents($release->guid);
        $this->assertStringContainsString('opaque@example.invalid', $stored);
        $this->assertStringContainsString('keep this metadata', $stored);
        $this->assertStringContainsString('bundle.r15', $stored);
        if ($repair) {
            foreach ([1, 2, 3] as $part) {
                $this->assertStringContainsString('part'.$part.'of3.CourseRepair@host', $stored);
            }
        }
        $this->assertSame(2, (int) DB::table('reconciled_artifacts')->value('epoch'));
    }

    public function test_legacy_partial_union_can_rescan_a_whole_file_then_prove_another_late_source(): void
    {
        [$service] = $this->ingestCourse(['bundle.r14', 'bundle.r15']);
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-01-01 14:00:00']);
        $this->seedLegacyPartialPosting();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('legacy-rescan-late')]);
        $nzbs = app(NzbService::class);
        $release = Release::query()->first();
        $this->assertTrue($nzbs->createNzbForRelease($release)->success);
        (require database_path('migrations/2026_09_10_140952_create_reconciled_artifact_operations.php'))->up();
        Schema::table('releases', function (Blueprint $table): void {
            foreach (['repair', 'rescan'] as $prefix) {
                $table->timestamp($prefix.'_attempted_at')->nullable();
                $table->string($prefix.'_outcome')->nullable();
                $table->double($prefix.'_target_completion')->nullable();
                $table->double($prefix.'_evaluated_target_completion')->nullable();
            }
            foreach (['pp_timeout_count', 'proc_nfo', 'proc_files', 'proc_srr', 'proc_crc32', 'proc_uid', 'proc_hash16k', 'proc_par2', 'proc_srrdb', 'proc_xxx', 'proc_media_movie'] as $column) {
                $table->integer($column)->default(1);
            }
        });
        DB::statement('CREATE TABLE video_data (releases_id INTEGER PRIMARY KEY)');
        DB::statement('CREATE TABLE audio_data (id INTEGER PRIMARY KEY, releases_id INTEGER)');
        DB::table('releases')->where('id', $release->id)->update(['firstarticle' => 100000, 'lastarticle' => 100033]);
        $lines = [];
        foreach ($this->courseHeaders as $header) {
            if (str_contains($header['Subject'], '"bundle.r14"')) {
                $lines[$header['Number']] = $header;
            }
        }
        $nntp = new FakeHeaderNntp($lines);
        $nntp->groupFirst = 100000;
        $nntp->groupLast = 100033;
        $binaries = new BinariesService(config: new BinariesConfig(echoCli: false));
        $binaries->setNntp($nntp);
        $rescan = new MissingFileRescanService($nzbs, $nntp, new RescanWindowResolver($binaries));
        $result = $rescan->rescan($release->fresh(), new MissingFileRescanOptions(windowMinutes: 0), new RescanRunBudget(1000));
        $this->assertSame(1, $result->filesRecovered, $result->reason);
        $rescanXml = $nzbs->readNzbContents($release->guid);
        $this->assertStringContainsString('bundle.r14', $rescanXml);
        $late = array_values(array_filter($this->courseHeaders, static fn ($header): bool => str_contains($header['Subject'], '"bundle.r15"')));
        (new TestBinariesHarness)->simulateScan($late, ['id' => 1, 'name' => 'alt.binaries.boneless']);
        $lateId = (int) DB::table('collections')->value('id');
        $this->assertSame('late_added', $service->reconcile($lateId, 1));
        $current = ArtifactInventory::load($nzbs->readNzbContents($release->guid));
        $this->assertSame('additive', $current->classifyAgainst(ArtifactInventory::load($rescanXml)));
        $this->assertCount(33, $current->files());
    }

    public static function ordinaryWriterCases(): iterable
    {
        yield 'replacement then late addition' => [false];
        yield 'replacement then real segment repair then late addition' => [true];
    }

    public function test_partial_verified_union_keeps_original_sources_pending(): void
    {
        (require database_path('migrations/2026_09_10_134847_add_reconciliation_admissions.php'))->up();
        [$service, $id] = $this->ingestCourse('bundle.r15');
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-01-01 14:00:00']);
        $this->assertSame('verified_incomplete', $service->reconcile($id, 1));
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(0, DB::table('collections')->whereNotNull('releases_id')->count());
        $this->assertTrue(CollectionOwnership::protects($id));
    }

    public function test_historical_dry_run_only_accounts_traffic_and_apply_preserves_source_artifacts(): void
    {
        $this->ingestCourse();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('historical-nzbs')]);
        $nzbs = app(NzbService::class);
        [$sources, $originals] = $this->historicalSources($nzbs);
        $recovery = app(HistoricalReconciliation::class);
        $before = DB::table('releases')->get()->toJson();
        $plan = $recovery->plan($sources);
        $this->assertTrue($plan['applicable']);
        $this->assertSame(100.0, $plan['completion']);
        $this->assertSame($sources[0], $plan['anchor']);
        $this->assertSame($before, DB::table('releases')->get()->toJson());
        foreach (['reconciliation_evidence', 'reconciliation_claims', 'reconciled_postings', 'reconciled_sources'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertGreaterThan(0, DB::table('reconciliation_traffic')->sum('actual'));
        $this->assertSame('applied', $recovery->apply($sources, null, $plan['digest']));
        $this->assertSame('already_applied', $recovery->apply($sources, null, $plan['digest']));
        $this->assertSame(17, DB::table('releases')->count());
        $anchor = Release::query()->findOrFail($plan['anchor']);
        $this->assertSame('Course.Set', $anchor->searchname);
        $this->assertSame(Category::OTHER_MISC, (int) $anchor->categories_id);
        $this->assertFalse((bool) $anchor->is_trusted_name);
        foreach ($originals as $id => [$path, $digest]) {
            if ($id !== $plan['anchor']) {
                $this->assertSame($digest, hash_file('sha256', $path));
            }
        }
    }

    public function test_unproved_different_families_do_not_gain_a_hold_from_shared_budget_deferrals(): void
    {
        (require database_path('migrations/2026_09_10_134847_add_reconciliation_admissions.php'))->up();
        [$service, $id] = $this->ingestCourse();
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-01-01 14:00:00']);
        config(['collection-reconciliation.hour_bytes' => 1]);
        foreach ([0, 61, 301] as $seconds) {
            $this->travel($seconds)->seconds();
            $this->assertSame('budget_exhausted', $service->reconcile($id, 1));
            $this->assertFalse(CollectionOwnership::protects($id));
        }
        $this->assertSame(0, (int) DB::table('reconciliation_claims')->where('collection_id', $id)->value('attempts'));
        $this->assertSame(0, DB::table('releases')->count());
    }

    public function test_failed_nzb_switch_retains_work_and_retries_the_same_inventory(): void
    {
        [$service, $id] = $this->ingestCourse();
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-01-01 14:00:00']);
        $this->assertSame('associated', $service->reconcile($id, 1));
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('failed-publication')]);
        $release = Release::query()->first();
        $nzbs = app(NzbService::class);
        $publisher = new class extends PostingPublication
        {
            protected function publish(string $temporary, string $path): bool
            {
                return false;
            }
        };
        $this->assertFalse($publisher->write($release, $nzbs)->success);
        $this->assertFalse($nzbs->nzbPath($release->guid));
        $this->assertSame(17, DB::table('collections')->count());
        $this->assertTrue($nzbs->createNzbForRelease($release)->success);
        $this->assertCount(33, (new PostingNzb)->parse($nzbs->readNzbContents($release->guid), 'retry'));
    }

    public function test_a_multi_video_bundle_cannot_take_one_members_trusted_name(): void
    {
        [$service, $id] = $this->ingestCourse();
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-01-01 14:00:00']);
        $this->assertSame('associated', $service->reconcile($id, 1));
        $release = Release::query()->first();
        $updater = app(ReleaseUpdateService::class);
        $updater->updateRelease($release, 'Single.Movie.2026.1080p', 'PAR2', true, 'PAR2, ', true, false);
        $this->assertSame('Course.Set', $release->fresh()->searchname);
        $this->assertSame(0, (int) $release->fresh()->is_trusted_name);
    }

    public function test_ingestion_invalidates_a_claim_and_cleanup_cannot_delete_its_sources(): void
    {
        [$service, $id] = $this->ingestCourse();
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-01-01 14:00:00']);
        $this->beforeFirstRead = function (): void {
            $ids = DB::table('collections')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $this->assertSame(0, app(CollectionCleanupService::class)->deleteCollectionsAndDescendants($ids));
            (new TestBinariesHarness)->simulateScan($this->courseHeaders, ['id' => 1, 'name' => 'alt.binaries.boneless']);
        };
        $this->assertSame('changed_inventory', $service->reconcile($id, 1));
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(33, DB::table('binaries')->count());
        $this->assertSame('associated', $service->reconcile($id, 1));
        $this->assertSame(1, DB::table('releases')->count());
    }

    public function test_late_addition_preserves_old_files_and_resumes_after_rename_before_commit(): void
    {
        [$service, $id] = $this->ingestCourse('bundle.r15');
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-01-01 14:00:00']);
        $this->seedLegacyPartialPosting();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('late-publication')]);
        $nzbs = app(NzbService::class);
        $release = Release::query()->first();
        $this->assertTrue($nzbs->createNzbForRelease($release)->success);
        $this->assertLessThan(100, (float) $release->fresh()->completion);
        $this->assertCount(32, (new PostingNzb)->parse($nzbs->readNzbContents($release->guid), 'before'));
        $late = array_values(array_filter($this->courseHeaders, static fn ($header): bool => str_contains($header['Subject'], '"bundle.r15"')));
        $this->assertCount(1, $late);
        (new TestBinariesHarness)->simulateScan($late, ['id' => 1, 'name' => 'alt.binaries.boneless']);
        $lateId = (int) DB::table('collections')->min('id');
        $this->app->instance(PostingPublication::class, new class extends PostingPublication
        {
            protected function publish(string $temporary, string $path): bool
            {
                parent::publish($temporary, $path);
                throw new \RuntimeException('simulated_crash_after_rename');
            }
        });
        $this->assertStringContainsString('simulated_crash_after_rename', $service->reconcile($lateId, 1));
        $this->assertSame('prepared', DB::table('reconciled_postings')->value('state'));
        $this->assertSame(1, DB::table('collections')->count());
        $this->app->instance(PostingPublication::class, new PostingPublication);
        $service->run(1, 1);
        $this->assertSame('published', DB::table('reconciled_postings')->value('state'));
        $this->assertCount(33, (new PostingNzb)->parse($nzbs->readNzbContents($release->guid), 'after'));
        $this->assertSame(100.0, (float) $release->fresh()->completion);
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(1, DB::table('releases')->count());
    }

    public function test_interrupted_historical_apply_resumes_and_fences_all_sources_until_commit(): void
    {
        $this->ingestCourse();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('historical-resume')]);
        $nzbs = app(NzbService::class);
        [$sources] = $this->historicalSources($nzbs);
        $recovery = app(HistoricalReconciliation::class);
        $plan = $recovery->plan($sources);
        $this->app->instance(PostingPublication::class, new class extends PostingPublication
        {
            protected function publish(string $temporary, string $path): bool
            {
                parent::publish($temporary, $path);
                throw new \RuntimeException('historical_crash');
            }
        });
        try {
            $recovery->apply($sources, null, $plan['digest']);
            $this->fail('Simulated crash must interrupt the handoff.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('historical_crash', $e->getMessage());
        }
        foreach ($sources as $id) {
            $this->assertFalse(BundleIdentity::allowsSingleTitle($id));
        }
        $this->app->instance(PostingPublication::class, new PostingPublication);
        $this->assertSame('already_applied', $recovery->apply($sources, null, $plan['digest']));
        $this->assertSame('Course.Set', Release::query()->findOrFail($plan['anchor'])->searchname);
        $this->assertTrue(BundleIdentity::allowsSingleTitle($sources[1]));
    }

    public function test_historical_apply_refuses_changes_and_active_processing_since_review(): void
    {
        $this->ingestCourse();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('historical-stale')]);
        [$sources] = $this->historicalSources(app(NzbService::class));
        $recovery = app(HistoricalReconciliation::class);
        $plan = $recovery->plan($sources);
        Release::query()->whereKey($sources[1])->update(['additional_pp_claimed_at' => now()]);
        try {
            $recovery->apply($sources, null, $plan['digest']);
            $this->fail('An active source must refuse a reviewed plan.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('stale or non-applicable', $e->getMessage());
        }
        $this->assertSame(0, DB::table('reconciled_postings')->count());
    }

    public function test_late_new_video_cannot_convert_a_trusted_single_title_into_a_bundle(): void
    {
        $videos = array_slice(array_keys(Par2Fixture::course()), 1, 14);
        [$service, $id] = $this->ingestCourse($videos);
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-01-01 14:00:00']);
        $this->seedLegacyPartialPosting();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('late-identity')]);
        $nzbs = app(NzbService::class);
        $release = Release::query()->first();
        $this->assertTrue($nzbs->createNzbForRelease($release)->success);
        $old = $nzbs->readNzbContents($release->guid);
        Release::query()->whereKey($release->id)->update(['is_trusted_name' => true, 'movieinfo_id' => 42]);
        $late = array_values(array_filter($this->courseHeaders, static fn ($header): bool => str_contains($header['Subject'], '"002-lesson-b.mkv"')));
        (new TestBinariesHarness)->simulateScan($late, ['id' => 1, 'name' => 'alt.binaries.boneless']);
        $this->assertSame('late_trusted_identity_conflict', $service->reconcile((int) DB::table('collections')->min('id'), 1));
        $this->assertSame($old, $nzbs->readNzbContents($release->guid));
        $this->assertSame(1, DB::table('collections')->count());
    }

    public function test_changed_unpublished_inventory_expires_without_losing_original_sources(): void
    {
        [$service, $id] = $this->ingestCourse();
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-01-01 14:00:00']);
        $this->assertSame('associated', $service->reconcile($id, 1));
        DB::table('parts')->where('id', DB::table('parts')->min('id'))->update(['size' => 99]);
        $this->travel(16)->minutes();
        Search::shouldReceive('deleteReleases')->once();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('expired-publication')]);
        $result = app(NzbService::class)->createNzbForRelease(Release::query()->first());
        $this->assertFalse($result->success);
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(0, DB::table('reconciled_postings')->count());
        $this->assertSame(17, DB::table('collections')->whereNull('releases_id')->count());
        $this->assertSame(33, DB::table('binaries')->count());
        $this->assertFalse(CollectionOwnership::protects($id));
    }

    public function test_complete_stock_payload_sets_and_missing_par2_fragments_require_no_article_downloads(): void
    {
        $headers = [];
        foreach (['Example.bin', 'Example.bin.par2', 'Example.bin.vol000+001.par2', '01-show.mp3', '02-show.mp3'] as $i => $filename) {
            $headers[] = ['Number' => 100000 + $i, 'Subject' => sprintf('[%02d/%02d] - "%s" yEnc (1/1)', $i < 3 ? $i + 1 : $i, $i < 3 ? 3 : 12, $filename),
                'From' => 'Synthetic Poster A', 'Date' => 'Thu, 01 Jan 2026 12:00:00 +0000', 'Message-ID' => '<stock-'.$i.'@example.invalid>',
                'Bytes' => 64, 'Xref' => 'news.example.invalid alt.binaries.boneless:'.(100000 + $i)];
        }
        (new TestBinariesHarness)->simulateScan($headers, ['id' => 1, 'name' => 'alt.binaries.boneless']);
        $this->assertSame(3, DB::table('collections')->count());
        $this->assertSame(1, DB::table('collections')->where('collection_regexes_id', 113)->where('declaredfiles', 3)->count());
        $pool = Mockery::mock(NntpProviderPool::class);
        $pool->shouldNotReceive('fetchBoundedArticle');
        $service = new PendingReconciler(new PendingInventory, new PostingEvidence($pool, new YencService));
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-01-01 14:00:00']);
        $service->run(1, 1);
        $this->assertSame(0, DB::table('reconciliation_traffic')->count());
        $this->assertSame(3, DB::table('collections')->count());
    }

    public function test_stale_preparation_cannot_revert_a_concurrent_successful_publication(): void
    {
        [$service, $id] = $this->ingestCourse();
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-01-01 14:00:00']);
        $this->assertSame('associated', $service->reconcile($id, 1));
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('stale-preparation')]);
        $nzbs = app(NzbService::class);
        $release = Release::query()->first();
        $staleNzbs = Mockery::mock($nzbs);
        $staleNzbs->shouldReceive('getNzbPath')->once()->andReturnUsing(function () use ($release, $nzbs): string {
            $this->assertTrue((new PostingPublication)->write($release, $nzbs)->success);

            return $nzbs->nzbPath($release->guid);
        });
        $result = (new PostingPublication)->write($release, $staleNzbs);
        $this->assertFalse($result->success);
        $this->assertSame('published', DB::table('reconciled_postings')->value('state'));
        $this->assertSame(NzbService::NZB_ADDED, (int) $release->fresh()->nzbstatus);
        $this->assertCount(33, (new PostingNzb)->parse($nzbs->readNzbContents($release->guid), 'published'));
    }

    public function test_changed_late_inventory_restores_the_old_posting_after_its_deadline(): void
    {
        [$service, $id] = $this->ingestCourse('bundle.r15');
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-01-01 14:00:00']);
        $this->seedLegacyPartialPosting();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('late-abort')]);
        $nzbs = app(NzbService::class);
        $release = Release::query()->first();
        $this->assertTrue($nzbs->createNzbForRelease($release)->success);
        $original = $nzbs->readNzbContents($release->guid);
        $late = array_values(array_filter($this->courseHeaders, static fn ($header): bool => str_contains($header['Subject'], '"bundle.r15"')));
        (new TestBinariesHarness)->simulateScan($late, ['id' => 1, 'name' => 'alt.binaries.boneless']);
        $this->app->instance(PostingPublication::class, new class extends PostingPublication
        {
            protected function publish(string $temporary, string $path): bool
            {
                parent::publish($temporary, $path);
                throw new \RuntimeException('late_crash');
            }
        });
        $lateId = (int) DB::table('collections')->min('id');
        $this->assertStringContainsString('late_crash', $service->reconcile($lateId, 1));
        DB::table('parts')->update(['size' => 99]);
        $this->travel(16)->minutes();
        $this->app->instance(PostingPublication::class, new PostingPublication);
        $service->run(1, 1);
        $this->assertSame('published', DB::table('reconciled_postings')->value('state'));
        $this->assertSame($original, $nzbs->readNzbContents($release->guid));
        $this->assertCount(32, PendingInventory::decode(DB::table('reconciled_postings')->value('inventory')));
        $this->assertSame(1, DB::table('collections')->whereNull('releases_id')->count());
        $this->assertSame(99, (int) DB::table('parts')->value('size'));
        $this->assertFalse(CollectionOwnership::protects($lateId));
    }

    /** @return array{list<int>, array<int, array{string, string}>} */
    private function historicalSources(NzbService $nzbs): array
    {
        $sources = $originals = [];
        foreach (DB::table('collections')->orderBy('id')->get() as $collection) {
            $files = (new PendingInventory)->load([(int) $collection->id]);
            $guid = (string) Str::uuid();
            $id = (int) Release::insertRelease(['name' => $collection->subject, 'searchname' => $collection->subject,
                'totalpart' => count($files), 'declaredfiles' => 33, 'groups_id' => 1, 'guid' => $guid,
                'postdate' => $collection->date, 'fromname' => $collection->fromname, 'size' => 100,
                'categories_id' => 7010, 'isrenamed' => 0, 'is_trusted_name' => false, 'predb_id' => 0, 'nzbstatus' => 1]);
            $xml = (new PostingNzb)->render($files);
            $path = $nzbs->getNzbPath($guid, 0, true);
            file_put_contents($path, gzencode($xml));
            $sources[] = $id;
            $originals[$id] = [$path, hash_file('sha256', $path)];
        }

        return [$sources, $originals];
    }

    private function seedLegacyPartialPosting(): void
    {
        $ids = DB::table('collections')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $files = (new PendingInventory)->load($ids);
        $decision = app(PostingEvidence::class)->resolve($files, 'legacy-test-proof', microtime(true) + 30);
        $this->assertCount(count($files), $decision->accepted);
        $source = DB::table('collections')->where('id', $ids[0])->first();
        $releaseId = (int) Release::insertRelease(['name' => $decision->label, ...Release::searchNameValues($decision->label),
            'totalpart' => count($files), 'declaredfiles' => $decision->declaredTotal, 'groups_id' => $source->groups_id,
            'guid' => (string) Str::uuid(), 'postdate' => $source->date, 'fromname' => $source->fromname,
            'size' => array_sum(array_map(static fn ($file) => array_sum(array_column($file->segments, 'bytes')), $files)),
            'categories_id' => Category::OTHER_MISC, 'isrenamed' => 0, 'is_trusted_name' => false, 'predb_id' => 0,
            'nzbstatus' => 0, 'completion' => $decision->completion()]);
        $digest = PendingInventory::digest($files);
        $postingId = DB::table('reconciled_postings')->insertGetId(['release_id' => $releaseId, 'digest' => PendingInventory::digest($decision->accepted),
            'source_digest' => $digest, 'state' => 'created', 'inventory' => PendingInventory::encode($decision->accepted),
            'decision' => json_encode($decision, JSON_THROW_ON_ERROR), 'independent_videos' => $decision->independentVideos()]);
        foreach ($ids as $id) {
            $collection = DB::table('collections')->where('id', $id)->first();
            DB::table('reconciled_sources')->insert(['posting_id' => $postingId, 'collection_hash' => bin2hex($collection->collectionhash),
                'group_id' => $source->groups_id, 'postdate' => $collection->date, 'source_id' => (string) $id]);
            DB::table('reconciliation_claims')->insert(['collection_id' => $id, 'revision' => $digest, 'reason' => 'associated',
                'release_id' => $releaseId, 'deadline' => now()->addSeconds(900)]);
        }
        DB::table('collections')->whereIn('id', $ids)->update(['releases_id' => $releaseId, 'filecheck' => 4]);
        DB::table('releases_groups')->insert(['releases_id' => $releaseId, 'groups_id' => $source->groups_id]);
    }

    /** @return array{PendingReconciler, int} */
    private function ingestCourse(array|string|null $omit = null, bool $multipart = false): array
    {
        $payloads = Par2Fixture::course();
        $manifest = Par2Fixture::metadata($payloads);
        $payloads['Course.Set.par2'] = $manifest;
        for ($i = 0; $i < 10; $i++) {
            $payloads[sprintf('Course.Set.vol%03d+001.par2', $i)] = $manifest;
        }
        $headers = $responses = [];
        $ordinal = 0;
        foreach ($payloads as $filename => $data) {
            $i = ++$ordinal;
            $id = sprintf('<fixture-%06d@example.invalid>', $i);
            $subject = sprintf('[%02d/33] - "%s" yEnc (1/1)', $i, $filename);
            $headers[] = ['Number' => 99999 + $i, 'Subject' => $subject, 'From' => 'Synthetic Poster A',
                'Date' => 'Thu, 01 Jan 2026 12:00:00 +0000', 'Message-ID' => $id, 'Bytes' => strlen($data),
                'Xref' => 'news.example.invalid alt.binaries.boneless:'.(99999 + $i)];
            if ($multipart && $filename === 'bundle.r10') {
                array_pop($headers);
                for ($part = 1; $part <= 3; $part++) {
                    $partId = '<part'.$part.'of3.CourseRepair@host>';
                    $partSubject = str_replace('(1/1)', '('.$part.'/3)', $subject);
                    $headers[] = ['Number' => 200000 + $part, 'Subject' => $partSubject, 'From' => 'Synthetic Poster A',
                        'Date' => 'Thu, 01 Jan 2026 12:00:00 +0000', 'Message-ID' => $partId, 'Bytes' => 100,
                        'Xref' => 'news.example.invalid alt.binaries.boneless:'.(200000 + $part)];
                    $responses['HEAD'.$partId] = "From: Synthetic Poster A\r\nSubject: {$partSubject}\r\nDate: Thu, 01 Jan 2026 12:00:00 +0000\r\nMessage-ID: {$partId}\r\n";
                }
            }
            $responses['HEAD'.$id] = "From: Synthetic Poster A\r\nSubject: {$subject}\r\nDate: Thu, 01 Jan 2026 12:00:00 +0000\r\nMessage-ID: {$id}\r\n";
            if (str_ends_with($filename, '.par2')) {
                $responses['BODY'.$id] = (new YencService)->encode($data, $filename);
            }
        }
        (new TestBinariesHarness)->simulateScan(array_values(array_filter($headers, static fn ($header): bool => ! array_any((array) $omit, static fn ($name): bool => str_contains($header['Subject'], '"'.$name.'"')))), ['id' => 1, 'name' => 'alt.binaries.boneless']);
        $this->assertSame(17 - count(array_filter((array) $omit, static fn ($name): bool => str_ends_with($name, '.mkv'))), DB::table('collections')->count());
        $this->assertSame(33 - count((array) $omit), DB::table('binaries')->count());
        $provider = new NntpProvider(1, 'fixture', 'example.invalid', 119, false, '', '', 1, 5, true);
        $client = Mockery::mock(BoundedProviderClient::class);
        $client->shouldReceive('fetchBoundedArticle')->andReturnUsing(function ($id, $head) use ($responses): BoundedArticleResponse {
            if ($this->beforeFirstRead !== null) {
                $hook = $this->beforeFirstRead;
                $this->beforeFirstRead = null;
                $hook();
            }
            $data = $responses[($head ? 'HEAD' : 'BODY').$id];

            return new BoundedArticleResponse($data, strlen($data));
        });
        $pool = new NntpProviderPool([$provider], clientFactory: static fn () => $client);
        $service = new PendingReconciler(new PendingInventory, new PostingEvidence($pool, new YencService));
        $id = (int) DB::table('collections')->min('id');

        $this->app->instance(PostingEvidence::class, new PostingEvidence($pool, new YencService));
        $this->courseHeaders = $headers;

        return [$service, $id];
    }
}
