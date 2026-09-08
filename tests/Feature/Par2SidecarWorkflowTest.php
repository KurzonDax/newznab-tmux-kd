<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Release;
use App\Models\Settings;
use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\DTO\ReleaseProcessingResult;
use App\Services\AdditionalProcessing\DTO\UnknownPayloadCandidate;
use App\Services\AdditionalProcessing\FreeDiskGuard;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseFilesArchiveFallback;
use App\Services\AdditionalProcessing\ReleaseProcessor;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\DTO\YencArticleMetadata;
use App\Services\NameFixing\NameFixingService;
use App\Services\NfoService;
use App\Services\NNTP\DTO\ArticleDownloadResult;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\Par2Sidecar\SidecarCombiner;
use App\Services\Par2Sidecar\SidecarEvidence;
use App\Services\Par2Sidecar\SidecarWork;
use App\Services\ReleaseImageService;
use App\Services\ReleaseRepair\RecoveryLease;
use App\Services\TempWorkspaceService;
use App\Services\YencService;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\Reconciliation\Par2Fixture;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

class Par2SidecarWorkflowTest extends TestCase
{
    use CreatesProcessingConfiguration;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('guid')->default('target');
            foreach (['name', 'searchname', 'searchname_normalized', 'display_name', 'fromname', 'leftguid'] as $column) {
                $table->string($column)->default('');
            }
            foreach (['size', 'totalpart', 'firstarticle', 'lastarticle', 'groups_id', 'categories_id', 'rarinnerfilecount', 'declaredfiles'] as $column) {
                $table->unsignedBigInteger($column)->default(0);
            }
            foreach (['jpgstatus', 'videostatus', 'pp_timeout_count', 'isrenamed', 'is_trusted_name', 'iscategorized', 'predb_id', 'nzbstatus', 'nfostatus', 'passwordstatus', 'haspreview',
                'proc_par2', 'proc_pp', 'proc_hash16k', 'proc_files', 'proc_nfo', 'proc_uid', 'proc_srr', 'proc_crc32', 'proc_media_movie',
                'videos_id', 'tv_episodes_id', 'gamesinfo_id'] as $column) {
                $table->integer($column)->default(0);
            }
            foreach (['movieinfo_id', 'musicinfo_id', 'consoleinfo_id', 'bookinfo_id', 'anidbid'] as $column) {
                $table->unsignedInteger($column)->nullable();
            }
            $table->string('imdbid')->nullable();
            $table->float('completion')->default(100);
            $table->dateTime('postdate')->default('2026-09-01 00:00:00');
            $table->dateTime('adddate')->nullable();
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->string('additional_pp_claim_token')->nullable();
            $table->timestamp('recovery_claimed_at')->nullable();
            $table->string('recovery_claim_token')->nullable();
        });
        (require database_path('migrations/2026_09_08_205107_create_par2_sidecar_evidence_tables.php'))->up();
        Schema::create('predb', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
        });
        Schema::create('par_hashes', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->char('hash', 32);
            $table->unique(['releases_id', 'hash']);
        });
        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('crc32')->default('');
            $table->boolean('passworded')->default(false);
            $table->timestamps();
            $table->unique(['releases_id', 'name']);
        });
        DB::table('releases')->insert(['id' => 1]);
        NzbCreationCandidateQuery::flushCapabilityCache();
    }

    protected function tearDown(): void
    {
        NzbCreationCandidateQuery::flushCapabilityCache();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[DataProvider('processingOrders')]
    public function test_matching_sidecar_is_named_appended_accounted_and_deleted_once(bool $sourceFirst, bool $addFiles): void
    {
        Search::spy();
        Event::fake([ReleaseNameFixed::class]);
        $this->seedPostingPair($sourceFirst, $addFiles);
        $worker = app(SidecarWork::class);
        $worker->run();
        $target = Release::query()->findOrFail(1);
        $this->assertSame('Example.Title.2014.1080p.WEB-DL.H.264-GRP', $target->searchname);
        $this->assertSame(1, (int) $target->is_trusted_name);
        $this->assertSame('done', DB::table('par2_sidecar_operations')->value('phase'), (string) DB::table('par2_sidecar_operations')->value('reason'));
        $this->assertNull(Release::query()->find(2));
        $this->assertSame(2200000, (int) $target->size);
        $this->assertSame(4, (int) $target->totalpart);
        $this->assertSame(100.0, (float) $target->completion);
        $this->assertSame($addFiles ? 1 : 0, (int) $target->rarinnerfilecount);
        $xml = app(NzbService::class)->readNzbContents($target->guid);
        $this->assertSame(2, count(simplexml_load_string($xml)->file));
        $this->assertSame('done', DB::table('par2_sidecar_operations')->value('phase'));
        $worker->run();
        $this->assertSame(2200000, (int) $target->fresh()->size);
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
    }

    #[DataProvider('processingOrders')]
    public function test_real_sniff_pipeline_in_both_arrival_orders_uses_only_selected_articles(bool $sourceFirst, bool $addFiles): void
    {
        Search::spy();
        Event::fake([ReleaseNameFixed::class]);
        $this->seedPostingPair($sourceFirst, $addFiles, capture: false);
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
        Schema::create('categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('root_categories_id');
        });
        Schema::create('root_categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->boolean('generate_previews')->default(true);
            $table->boolean('discard_executables')->default(false);
        });
        DB::table('categories')->insert(['id' => 8010, 'root_categories_id' => 8000]);
        DB::table('root_categories')->insert(['id' => 8000]);
        DB::table('releases')->update(['groups_id' => 1, 'nfostatus' => 1, 'haspreview' => -1]);
        $worker = app(SidecarWork::class);
        foreach ($sourceFirst ? [2, 1] : [1, 2] as $id) {
            $result = $this->processPosting($id, $addFiles);
            $this->assertSame(1, $result->downloadMetrics->networkRequests);
            $this->assertSame(1, $result->downloadMetrics->logicalRequests);
            $this->assertSame(1, $result->payloadSniffMetrics->candidateCount);
            if ($id === 2) {
                $this->assertSame('random', Release::query()->findOrFail(2)->searchname);
                $this->assertSame(0, DB::table('release_files')->where('releases_id', 2)->count());
                $this->assertTrue((bool) DB::table('par2_sidecar_inventories')->where('releases_id', 2)->value('pure'));
            }
            $this->travel(6)->minutes();
            $worker->run();
        }
        $this->assertSame('done', DB::table('par2_sidecar_operations')->value('phase'), (string) DB::table('par2_sidecar_operations')->value('reason'));
        $this->assertNull(Release::query()->find(2));
        $this->assertSame(2200000, (int) Release::query()->findOrFail(1)->size);
        $this->assertSame($addFiles ? 1 : 0, (int) Release::query()->findOrFail(1)->rarinnerfilecount);
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
    }

    private function processPosting(int $id, bool $addFiles): ReleaseProcessingResult
    {
        $config = $this->makeConfig(['addPAR2Files' => $addFiles, 'renamePar2' => true]);
        $payload = "\x1a\x45\xdf\xa3".str_repeat('A', 16380).str_repeat('B', 1983616);
        $data = $id === 1 ? substr($payload, 0, 768000)
            : Par2Fixture::metadata(['Example.Title.2014.1080p.WEB-DL.H.264-GRP.mkv' => $payload]);
        $yenc = new YencService;
        $article = $yenc->encode($data, 'random');
        if ($id === 1) {
            $article = str_replace('line=128 size=768000', 'part=1 total=3 line=128 size=2000000', $article);
            $article = preg_replace('/(^=ybegin[^\r]+\r\n)/', "$1=ypart begin=1 end=768000\r\n", $article);
            $article = str_replace('=yend size=768000', '=yend part=1 size=768000', $article);
        }
        $nntp = \Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessagesByMessageIDWithCrcStatus')->once()->with(['file'.$id.'part1@fixture'])
            ->andReturnUsing(static function () use ($yenc, $article) {
                $decoded = $yenc->decodeWithCrcStatus($article);

                return new ArticleDownloadResult($decoded->data, metadata: $decoded->metadata);
            });
        $metrics = new PersistenceMetricsCollector;
        $sync = new ReleaseSearchSyncCoordinator($metrics);
        $nzb = app(NzbService::class);
        $manager = new ReleaseFileManager($config, new ReleaseImageService,
            new NfoService, $nzb, new NameFixingService, searchSyncCoordinator: $sync);
        $archive = \Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('getPar2Info')->andReturn(new Par2Info);
        $media = \Mockery::mock(MediaExtractionService::class);
        $media->shouldReceive('processVideoFile')->andReturn([]);
        $output = \Mockery::mock(ConsoleOutputService::class)->shouldIgnoreMissing();
        $processor = new ReleaseProcessor($config,
            new NzbContentParser($nzb, new NzbParserService),
            new AdditionalWorkPlanner($config), $archive, $media,
            new UsenetDownloadService($config, $nntp), $manager,
            \Mockery::mock(ReleaseFilesArchiveFallback::class),
            new TempWorkspaceService, $output, $sync, $metrics,
            freeDiskGuard: new FreeDiskGuard(static fn (string $path): float => 900,
                static fn (string $path): float => 1000));

        return $processor->process(new ReleaseProcessingContext(Release::query()->findOrFail($id)), $this->makeTempDirectory('processing').'/');
    }

    public function test_explicit_par_hash_sweep_runs_sidecars_with_no_legacy_candidates(): void
    {
        Search::spy();
        Event::fake([ReleaseNameFixed::class]);
        $this->seedPostingPair();
        DB::table('releases')->update(['proc_hash16k' => 1]);
        (new NameFixingService)->fixNamesWithParHash(2, true, 2, true, false);
        $this->assertNull(Release::query()->find(2));
    }

    public static function processingOrders(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    #[DataProvider('crashPhases')]
    public function test_crashes_resume_without_duplicate_naming_membership_or_accounting(string $phase): void
    {
        Search::spy();
        Event::fake([ReleaseNameFixed::class]);
        $this->seedPostingPair();
        $combiner = app(InterruptingSidecarCombiner::class);
        $combiner->failAfter = $phase;
        $this->app->instance(SidecarCombiner::class, $combiner);
        $worker = app(SidecarWork::class);
        $worker->run();
        $this->assertNotSame('done', DB::table('par2_sidecar_operations')->value('phase'));
        $this->travel(4)->days();
        Settings::settingsUpsert(['par2_sidecar_absorb' => '0']);
        $worker->run();
        $this->assertSame('done', DB::table('par2_sidecar_operations')->value('phase'), (string) DB::table('par2_sidecar_operations')->value('reason'));
        $this->assertNull(Release::query()->find(2));
        $this->assertSame(2200000, (int) Release::query()->findOrFail(1)->size);
        $xml = app(NzbService::class)->readNzbContents(str_repeat('1', 40));
        $this->assertSame(2, count(simplexml_load_string($xml)->file));
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
    }

    public static function crashPhases(): array
    {
        return array_map(static fn (string $phase): array => [$phase], ['selected', 'named', 'nzb_written', 'delete_pending', 'source_deleted']);
    }

    public function test_a_new_source_claim_during_deletion_handoff_retries_deletion_only(): void
    {
        Search::spy();
        Event::fake([ReleaseNameFixed::class]);
        $this->seedPostingPair();
        $combiner = app(InterruptingSidecarCombiner::class);
        $combiner->onPhase = static function (string $phase): void {
            if ($phase === 'source_lease_released') {
                DB::table('releases')->where('id', 2)->update(['additional_pp_claimed_at' => now(), 'additional_pp_claim_token' => 'new-owner']);
            }
        };
        $this->app->instance(SidecarCombiner::class, $combiner);
        $worker = app(SidecarWork::class);
        $worker->run();
        $this->assertSame('delete_pending', DB::table('par2_sidecar_operations')->value('phase'));
        $this->assertNotNull(Release::query()->find(2));
        $this->assertSame(2200000, (int) Release::query()->findOrFail(1)->size);
        $combiner->onPhase = null;
        ReleaseClaimant::clearClaim(2, 'new-owner');
        $this->travel(2)->minutes();
        $worker->run();
        $this->assertNull(Release::query()->find(2));
        $this->assertSame(2200000, (int) Release::query()->findOrFail(1)->size);
    }

    public function test_disabled_absorption_names_only_and_expires_only_unselected_evidence(): void
    {
        Search::spy();
        Event::fake([ReleaseNameFixed::class]);
        $this->seedPostingPair();
        Settings::settingsUpsert(['par2_sidecar_absorb' => '0']);
        DB::table('releases')->update(['postdate' => '2020-01-01 00:00:00']);
        app(SidecarWork::class)->run();
        $this->assertSame(1, (int) Release::query()->findOrFail(1)->isrenamed);
        $this->assertNotNull(Release::query()->find(2));
        $this->assertSame('done', DB::table('par2_sidecar_operations')->value('phase'));
        $this->assertSame('absorb_disabled', DB::table('par2_sidecar_operations')->value('reason'));
        $this->assertSame(2100000, (int) Release::query()->findOrFail(1)->size);
    }

    public function test_expired_prefixes_cannot_start_work_but_unmatched_prefixes_wait_for_late_descriptors(): void
    {
        Search::spy();
        Event::fake([ReleaseNameFixed::class]);
        $this->seedPostingPair();
        $descriptors = DB::table('par2_file_descriptors')->get()->map(static fn (object $row): array => (array) $row)->all();
        DB::table('par2_file_descriptors')->delete();
        $worker = app(SidecarWork::class);
        $worker->run();
        $this->assertSame('pending', DB::table('payload_prefix_hashes')->value('state'));
        DB::table('par2_file_descriptors')->insert($descriptors);
        $this->travel(73)->hours();
        $worker->run();
        $this->assertSame('expired', DB::table('payload_prefix_hashes')->value('state'));
        $this->assertSame(0, DB::table('par2_sidecar_operations')->count());
    }

    public function test_late_descriptors_match_after_a_no_match_backoff(): void
    {
        Search::spy();
        Event::fake([ReleaseNameFixed::class]);
        $this->seedPostingPair();
        $descriptors = DB::table('par2_file_descriptors')->get()->map(static fn (object $row): array => (array) $row)->all();
        DB::table('par2_file_descriptors')->delete();
        $worker = app(SidecarWork::class);
        $worker->run();
        $this->assertSame('no_match', DB::table('payload_prefix_hashes')->value('reason'));
        DB::table('par2_file_descriptors')->insert($descriptors);
        $this->travel(6)->minutes();
        $worker->run();
        $this->assertNull(Release::query()->find(2));
        $this->assertSame('done', DB::table('par2_sidecar_operations')->value('phase'));
    }

    public function test_a_changed_source_at_deletion_handoff_is_retained(): void
    {
        Search::spy();
        Event::fake([ReleaseNameFixed::class]);
        $this->seedPostingPair();
        $combiner = app(InterruptingSidecarCombiner::class);
        $combiner->onPhase = function (string $phase): void {
            if ($phase === 'source_lease_released') {
                $nzb = app(NzbService::class);
                $guid = str_repeat('2', 40);
                $changed = str_replace('file2part1@fixture', 'replacement@fixture', $nzb->readNzbContents($guid));
                $this->assertTrue($nzb->replaceNzbContents($guid, $changed)->success);
            }
        };
        $this->app->instance(SidecarCombiner::class, $combiner);
        app(SidecarWork::class)->run();
        $this->assertSame('delete_pending', DB::table('par2_sidecar_operations')->value('phase'));
        $this->assertNotNull(Release::query()->find(2));
        $this->assertSame(2200000, (int) Release::query()->findOrFail(1)->size);
    }

    public function test_a_competing_worker_cannot_repeat_a_selected_operation(): void
    {
        Search::spy();
        Event::fake([ReleaseNameFixed::class]);
        $this->seedPostingPair();
        $combiner = app(InterruptingSidecarCombiner::class);
        $combiner->onPhase = static function (string $phase): void {
            if ($phase === 'named') {
                app(SidecarWork::class)->run();
            }
        };
        $this->app->instance(SidecarCombiner::class, $combiner);
        app(SidecarWork::class)->run();
        $this->assertSame(1, DB::table('par2_sidecar_operations')->count());
        $this->assertSame('done', DB::table('par2_sidecar_operations')->value('phase'));
        $this->assertSame(2200000, (int) Release::query()->findOrFail(1)->size);
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
    }

    public function test_a_claim_after_successful_naming_remains_retryable(): void
    {
        Search::spy();
        Event::fake([ReleaseNameFixed::class]);
        $this->seedPostingPair();
        $combiner = app(InterruptingSidecarCombiner::class);
        $combiner->failAfter = 'named';
        $this->app->instance(SidecarCombiner::class, $combiner);
        $worker = app(SidecarWork::class);
        $worker->run();
        DB::table('releases')->where('id', 1)->update(['additional_pp_claimed_at' => now(), 'additional_pp_claim_token' => 'owner']);
        $this->travel(2)->minutes();
        $worker->run();
        $this->assertSame('named', DB::table('par2_sidecar_operations')->value('phase'));
        $this->assertSame('claim_contention', DB::table('par2_sidecar_operations')->value('reason'));
        $this->assertSame(1, (int) Release::query()->findOrFail(1)->isrenamed);
        ReleaseClaimant::clearClaim(1, 'owner');
        $this->travel(2)->minutes();
        $worker->run();
        $this->assertNull(Release::query()->find(2));
    }

    public function test_partial_message_overlap_never_authorizes_accounting_or_deletion(): void
    {
        Search::spy();
        Event::fake([ReleaseNameFixed::class]);
        $this->seedPostingPair();
        $nzb = app(NzbService::class);
        $guid = str_repeat('2', 40);
        $xml = str_replace('file2part1@fixture', 'file1part1@fixture', $nzb->readNzbContents($guid));
        $this->assertTrue($nzb->replaceNzbContents($guid, $xml)->success);
        DB::table('par2_file_descriptors')->where('releases_id', 2)->update(['fingerprint' => hash('sha256', $xml)]);
        DB::table('par2_sidecar_inventories')->where('releases_id', 2)->update(['fingerprint' => hash('sha256', $xml)]);
        app(SidecarWork::class)->run();
        $this->assertSame('named', DB::table('par2_sidecar_operations')->value('phase'));
        $this->assertSame(2100000, (int) Release::query()->findOrFail(1)->size);
        $this->assertNotNull(Release::query()->find(2));
    }

    public function test_recovery_holds_are_excluded_from_available_backlog(): void
    {
        DB::table('releases')->where('id', 1)->update(['leftguid' => '1']);
        $lease = RecoveryLease::acquire(Release::query()->findOrFail(1));
        $this->assertNotNull($lease);
        $builder = static fn () => Release::query()->from('releases as r');
        $this->assertSame(['total' => 1, 'available' => 0], ReleaseClaimant::backlogCounts($builder));
        $lease->release();
        $this->assertSame(['total' => 1, 'available' => 1], ReleaseClaimant::backlogCounts($builder));
    }

    public function test_short_payload_hashes_the_entire_file_and_discard_drops_queued_evidence(): void
    {
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
        $candidate = new UnknownPayloadCandidate('random', 'short@fixture', 1, 11000, 11000, 0, [1], 1, 0, 'inventory');
        $data = str_repeat('A', 10000);
        $capture = new SidecarEvidence;
        $capture->queuePrefix($context, $candidate, $data, new YencArticleMetadata(10000, 1, 1, 0, 10000));
        $this->assertSame(md5($data), $context->pendingPayloadPrefixes[0]['prefix_hash']);
        $context->releaseDiscarded = true;
        $capture->flush($context);
        $this->assertSame(0, DB::table('payload_prefix_hashes')->count());
        $this->assertSame([], $context->pendingPayloadPrefixes);
    }

    public function test_an_ap_claim_between_precheck_and_lease_stamp_wins_atomically(): void
    {
        Search::spy();
        $this->seedPostingPair();
        $interleaved = false;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$interleaved): void {
            if (! $interleaved && str_starts_with(strtolower($sql), 'update ') && str_contains($sql, 'recovery_claimed_at')) {
                $interleaved = true;
                DB::table('releases')->where('id', 1)->update(['additional_pp_claimed_at' => now(), 'additional_pp_claim_token' => 'winning-ap']);
            }
        });
        app(SidecarWork::class)->run();
        $this->assertTrue($interleaved);
        $this->assertSame(0, DB::table('par2_sidecar_operations')->count());
        $this->assertSame('winning-ap', Release::query()->findOrFail(1)->additional_pp_claim_token);
        $this->assertSame(0, (int) Release::query()->findOrFail(1)->isrenamed);
        $this->assertNotNull(Release::query()->find(2));
    }

    public function test_subject_named_descriptor_capture_persists_overflow_ambiguity(): void
    {
        Search::spy();
        $this->seedPostingPair();
        DB::table('par2_file_descriptors')->delete();
        DB::table('par2_sidecar_inventories')->where('releases_id', 2)->delete();
        $payload = "\x1a\x45\xdf\xa3".str_repeat('A', 16380).str_repeat('B', 1983616);
        $bytes = Par2Fixture::metadata(['Example.Title.2014.1080p.WEB-DL.H.264-GRP.mkv' => $payload]);
        for ($index = 0; $index < 1025; $index++) {
            $bytes .= Par2Fixture::metadata(['Other'.$index.'.mkv' => 'payload']);
        }
        (new SidecarEvidence)->storeParsedDescriptors(2, $bytes);
        $this->assertSame(1025, DB::table('par2_file_descriptors')->where('naming_ambiguous', true)->count());
        app(SidecarWork::class)->run();
        $this->assertSame('ambiguous_inventory', DB::table('payload_prefix_hashes')->value('reason'));
        $this->assertSame(0, DB::table('par2_sidecar_operations')->count());
    }

    #[DataProvider('ambiguityCaptureOrders')]
    public function test_an_ambiguous_capture_without_descriptors_is_sticky_for_the_current_nzb(bool $ambiguousFirst): void
    {
        Search::spy();
        $this->seedPostingPair();
        DB::table('par2_file_descriptors')->delete();
        DB::table('par2_sidecar_inventories')->where('releases_id', 2)->delete();
        $payload = "\x1a\x45\xdf\xa3".str_repeat('A', 16380).str_repeat('B', 1983616);
        $valid = Par2Fixture::metadata(['Example.Title.2014.1080p.WEB-DL.H.264-GRP.mkv' => $payload]);
        $files = [];
        for ($index = 0; $index < 1025; $index++) {
            $files['Other'.$index.'.mkv'] = 'payload';
        }
        $invalid = Par2Fixture::metadata($files);
        foreach ($ambiguousFirst ? [$invalid, $valid] : [$valid, $invalid] as $bytes) {
            (new SidecarEvidence)->storeParsedDescriptors(2, $bytes);
        }
        $this->assertSame(1, DB::table('par2_file_descriptors')->count());
        app(SidecarWork::class)->run();
        $this->assertSame('ambiguous_inventory', DB::table('payload_prefix_hashes')->value('reason'));
        $this->assertSame(0, DB::table('par2_sidecar_operations')->count());
    }

    public static function ambiguityCaptureOrders(): array
    {
        return [[true], [false]];
    }

    public function test_changed_target_inventory_invalidates_prefix_before_naming(): void
    {
        Search::spy();
        $this->seedPostingPair();
        $nzb = app(NzbService::class);
        $guid = str_repeat('1', 40);
        $xml = str_replace('file1part1@fixture', 'replacement@fixture', $nzb->readNzbContents($guid));
        $this->assertTrue($nzb->replaceNzbContents($guid, $xml)->success);
        app(SidecarWork::class)->run();
        $this->assertSame('invalid_prefix', DB::table('payload_prefix_hashes')->value('reason'));
        $this->assertSame(0, DB::table('par2_sidecar_operations')->count());
    }

    protected function seedPostingPair(bool $sourceFirst = false, bool $addFiles = true, bool $capture = true): void
    {
        $root = $this->makeTempDirectory('sidecar-nzbs').'/';
        config(['nntmux_settings.path_to_nzbs' => $root, 'nntmux_settings.add_par2' => $addFiles,
            'nntmux_settings.covers_path' => $this->makeTempDirectory('sidecar-covers'), 'nntmux.echocli' => false]);
        $payload = "\x1a\x45\xdf\xa3".str_repeat('A', 16380).str_repeat('B', 1983616);
        $par2 = Par2Fixture::metadata(['Example.Title.2014.1080p.WEB-DL.H.264-GRP.mkv' => $payload]);
        foreach (($sourceFirst ? [2 => 1, 1 => 3] : [1 => 3, 2 => 1]) as $id => $segments) {
            $guid = str_repeat((string) $id, 40);
            DB::table('releases')->updateOrInsert(['id' => $id], ['guid' => $guid, 'leftguid' => (string) $id,
                'name' => 'random', 'searchname' => 'random', 'categories_id' => 8010, 'nzbstatus' => 1,
                'size' => $id === 1 ? 2100000 : 100000, 'totalpart' => $segments, 'declaredfiles' => 1,
                'firstarticle' => $id * 10, 'lastarticle' => $id * 10 + 3]);
            $xml = '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><file poster="fixture" date="100" subject="random yEnc (1/'.$segments.')"><groups><group>alt.binaries.test</group></groups><segments>';
            for ($part = 1; $part <= $segments; $part++) {
                $bytes = $id === 2 ? 100000 : ($part === 3 ? 500000 : 800000);
                $xml .= '<segment bytes="'.$bytes.'" number="'.$part.'">file'.$id.'part'.$part.'@fixture</segment>';
            }
            $xml .= '</segments></file></nzb>';
            mkdir($root.$id);
            file_put_contents($root.$id.'/'.$guid.'.nzb.gz', gzencode($xml));
            if (! $capture) {
                continue;
            }
            $context = new ReleaseProcessingContext(Release::query()->findOrFail($id));
            $context->nzbContents = (new NzbParserService)->parseNzbFileList($xml);
            $candidate = new UnknownPayloadCandidate('random', 'file'.$id.'part1@fixture', $segments, 2100000, 768000, 0,
                range(1, $segments), $segments, 0, hash('sha256', $xml));
            $capture = new SidecarEvidence;
            $capture->queueClassification($context, $candidate, $id === 1 ? 'matroska' : 'par2', $id === 1 ? substr($payload, 0, 768000) : $par2);
            if ($id === 1) {
                $capture->queuePrefix($context, $candidate, substr($payload, 0, 768000), new YencArticleMetadata(2000000, 1, 3, 0, 768000));
            } else {
                $context->purePar2Sidecar = true;
                DB::table('par_hashes')->insert(['releases_id' => 2, 'hash' => md5(substr($payload, 0, 16384))]);
            }
            $capture->flush($context);
        }
    }

    public function test_recovery_cannot_acquire_a_release_held_by_additional_processing(): void
    {
        DB::table('releases')->where('id', 1)->update(['additional_pp_claimed_at' => now(), 'additional_pp_claim_token' => 'ap']);
        $this->assertNull(RecoveryLease::acquire(Release::query()->findOrFail(1)));
    }

    public function test_prefix_is_queued_until_finalize_and_identical_retries_preserve_capture_time(): void
    {
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
        $candidate = new UnknownPayloadCandidate('random', 'article@fixture', 3, 2100000, 800000, 0,
            segmentNumbers: [1, 2, 3], declaredSegments: 3, nzbFileIndex: 0, fingerprint: 'inventory');
        $capture = new SidecarEvidence;
        $capture->queuePrefix($context, $candidate, str_repeat('A', 768000), new YencArticleMetadata(2000000, 1, 3, 0, 768000));
        $this->assertSame(0, DB::table('payload_prefix_hashes')->count());
        $capture->flush($context);
        $first = DB::table('payload_prefix_hashes')->first();
        $this->assertSame('4534f12102d235344cf8dda748f0cabf', $first->prefix_hash);
        $this->assertSame(2000000, $first->raw_size);
        $this->travel(1)->hours();
        $capture->queuePrefix($context, $candidate, str_repeat('A', 768000), new YencArticleMetadata(2000000, 1, 3, 0, 768000));
        $capture->flush($context);
        $this->assertSame(1, DB::table('payload_prefix_hashes')->count());
        $this->assertSame($first->captured_at, DB::table('payload_prefix_hashes')->value('captured_at'));
        $this->assertSame(0, DB::table('par_hashes')->count());
    }
}

class InterruptingSidecarCombiner extends SidecarCombiner
{
    public ?string $failAfter = null;

    public ?\Closure $onPhase = null;

    protected function checkpoint(string $phase): void
    {
        if ($this->onPhase !== null) {
            ($this->onPhase)($phase);
        }
        if ($this->failAfter === $phase) {
            $this->failAfter = null;
            throw new \RuntimeException('injected_'.$phase);
        }
    }
}
