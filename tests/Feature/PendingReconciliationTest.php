<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\CollectionCleanupService;
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
use App\Services\NNTP\DTO\BoundedArticleResponse;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\YencService;
use Database\Seeders\CollectionRegexesTableSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\Reconciliation\CreatesPostingSchema;
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

    public function test_ingested_course_fragments_are_associated_once_after_the_frontier_is_quiet(): void
    {
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

    public function test_budget_exhaustion_and_expired_leases_never_extend_the_original_hold(): void
    {
        [$service, $id] = $this->ingestCourse();
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-01-01 14:00:00']);
        config(['collection-reconciliation.hour_bytes' => 1]);
        $this->assertSame('budget_exhausted', $service->reconcile($id, 1));
        $deadline = DB::table('reconciliation_claims')->where('collection_id', $id)->value('deadline');
        $this->assertTrue(CollectionOwnership::protects($id));
        $this->assertSame('unavailable_claim', $service->reconcile($id, 1));
        $this->travel(61)->seconds();
        $this->assertSame('budget_exhausted', $service->reconcile($id, 1));
        $this->travel(301)->seconds();
        $this->assertSame('budget_exhausted', $service->reconcile($id, 1));
        $this->assertFalse(CollectionOwnership::protects($id));
        $this->assertSame($deadline, DB::table('reconciliation_claims')->where('collection_id', $id)->value('deadline'));
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
        $this->assertSame('associated', $service->reconcile($id, 1));
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
        $this->assertSame('associated', $service->reconcile($id, 1));
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
        $this->assertSame('associated', $service->reconcile($id, 1));
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

    /** @return array{PendingReconciler, int} */
    private function ingestCourse(array|string|null $omit = null): array
    {
        $payloads = Par2Fixture::course();
        $manifest = Par2Fixture::metadata($payloads);
        $payloads['Course.Set.par2'] = $manifest;
        for ($i = 0; $i < 10; $i++) {
            $payloads[sprintf('Course.Set.vol%03d+001.par2', $i)] = $manifest;
        }
        $headers = $responses = [];
        foreach ($payloads as $filename => $data) {
            $i = count($headers) + 1;
            $id = sprintf('<fixture-%06d@example.invalid>', $i);
            $subject = sprintf('[%02d/33] - "%s" yEnc (1/1)', $i, $filename);
            $headers[] = ['Number' => 99999 + $i, 'Subject' => $subject, 'From' => 'Synthetic Poster A',
                'Date' => 'Thu, 01 Jan 2026 12:00:00 +0000', 'Message-ID' => $id, 'Bytes' => strlen($data),
                'Xref' => 'news.example.invalid alt.binaries.boneless:'.(99999 + $i)];
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
