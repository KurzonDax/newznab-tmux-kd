<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Enums\HeaderScanDirection;
use App\Enums\ReleaseRepairOutcome;
use App\Facades\Search;
use App\Models\Release;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\ReleaseProcessor;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\HeaderStorageService;
use App\Services\MovieService;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NfoService;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\ObfuscationRecovery\RecoveryArchiveInspection;
use App\Services\ObfuscationRecovery\RecoveryArtifact;
use App\Services\ObfuscationRecovery\RecoveryArtifacts;
use App\Services\ObfuscationRecovery\RecoveryBootstrap;
use App\Services\ObfuscationRecovery\RecoveryBundleRefresh;
use App\Services\ObfuscationRecovery\RecoveryCachedPrefix;
use App\Services\ObfuscationRecovery\RecoveryCachedReader;
use App\Services\ObfuscationRecovery\RecoveryCapture;
use App\Services\ObfuscationRecovery\RecoveryCaptureBatch;
use App\Services\ObfuscationRecovery\RecoveryCbpVerifier;
use App\Services\ObfuscationRecovery\RecoveryCompaction;
use App\Services\ObfuscationRecovery\RecoveryComponents;
use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryControl;
use App\Services\ObfuscationRecovery\RecoveryEvidence;
use App\Services\ObfuscationRecovery\RecoveryFileRole;
use App\Services\ObfuscationRecovery\RecoveryHeads;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use App\Services\ObfuscationRecovery\RecoveryIdentityPolicy;
use App\Services\ObfuscationRecovery\RecoveryManifest;
use App\Services\ObfuscationRecovery\RecoveryMaterialization;
use App\Services\ObfuscationRecovery\RecoveryNaming;
use App\Services\ObfuscationRecovery\RecoveryNzbRestore;
use App\Services\ObfuscationRecovery\RecoveryNzbVerifier;
use App\Services\ObfuscationRecovery\RecoveryPar2;
use App\Services\ObfuscationRecovery\RecoveryPlan;
use App\Services\ObfuscationRecovery\RecoveryPreparation;
use App\Services\ObfuscationRecovery\RecoveryPublicationCoverage;
use App\Services\ObfuscationRecovery\RecoveryPublications;
use App\Services\ObfuscationRecovery\RecoveryReleaseGate;
use App\Services\ObfuscationRecovery\RecoveryRunDiscovery;
use App\Services\ObfuscationRecovery\RecoveryRunRefresh;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWork;
use App\Services\Par2Processor;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseImageService;
use App\Services\ReleaseRepair\MissingFileRescanOptions;
use App\Services\ReleaseRepair\MissingFileRescanService;
use App\Services\ReleaseRepair\RecoveryLease;
use App\Services\ReleaseRepair\ReleaseRepairOptions;
use App\Services\ReleaseRepair\ReleaseRepairService;
use App\Services\ReleaseRepair\RescanRunBudget;
use App\Services\Releases\ReleaseManagementService;
use App\Services\TvProcessing\Providers\AbstractTvProvider;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NeverBlacklistedService;
use Tests\Support\ObfuscationRecovery\CreatesRecoveryCbpSchema;
use Tests\Support\ObfuscationRecovery\CreatesRecoveryReleaseSchema;
use Tests\Support\ObfuscationRecovery\LocalMediaFixture;
use Tests\Support\ObfuscationRecovery\MediaPostingFixture;
use Tests\Support\ObfuscationRecovery\RarPostingFixture;
use Tests\Support\ObfuscationRecovery\SyntheticPosting;
use Tests\TestCase;

final class RecoveryPreparationTest extends TestCase
{
    use CreatesRecoveryCbpSchema;
    use CreatesRecoveryReleaseSchema;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.fixture', 'obfuscation_recovery_profile' => 'media']);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_captured_media_reaches_a_verified_plan_using_cached_inventory_and_anchor_evidence(): void
    {
        [$artifacts, $cache, $bundle] = $this->captured();
        $work = app(RecoveryWork::class);
        $work->enqueueForBundle(RecoveryStage::Discover, (int) $bundle->id, (int) $bundle->revision, 'prepare', []);
        $result = (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover));
        $this->assertSame('ready', $result);
        $ready = DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->first();
        $this->assertSame('ready', $ready->state);
        $this->assertNotNull($ready->manifest_verified_at);
        $plan = RecoveryPlan::fromArray(json_decode($ready->sealed_plan, true));
        $this->assertSame(2, $plan->plannedFiles());
        $this->assertSame(5, $plan->plannedParts());
        $this->assertSame(0, $plan->declaredFiles());
        $this->assertSame(2, DB::table('obfuscation_recovery_files')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertNotNull($work->claim(RecoveryStage::Publish));
    }

    public function test_published_coverage_is_rechecked_and_only_previously_verified_expired_coverage_can_support_a_sealed_plan(): void
    {
        [$artifacts, $cache, $bundle] = $this->captured();
        $work = app(RecoveryWork::class);
        $work->enqueueForBundle(RecoveryStage::Discover, (int) $bundle->id, (int) $bundle->revision, 'prepare', []);
        $this->assertSame('ready', (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover)));
        $ready = DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->first();
        $gate = new RecoveryPublicationCoverage;
        $this->assertTrue($gate->ready($ready));
        DB::table('obfuscation_recovery_scans')->update(['complete' => false, 'capture_outcome' => 'raw_expired']);
        DB::table('obfuscation_recovery_scans')->update(['created_at' => now()->subDays(31)]);
        $this->assertGreaterThan(0, app(RecoveryCompaction::class)->coverage());
        $this->assertSame(0, DB::table('obfuscation_recovery_scans')->where('date_order_consistent', true)
            ->where('last_postdate', '<=', now())->whereNotNull('returned_ranges')->count());
        $this->assertTrue($gate->ready($ready));
        DB::table('obfuscation_recovery_frontiers')->update(['head_observed' => false]);
        $this->assertFalse($gate->ready($ready));
        DB::table('obfuscation_recovery_frontiers')->update(['head_observed' => true]);
        DB::table('obfuscation_recovery_coverage')->where('kind', 'retained')->delete();
        $this->assertFalse($gate->ready($ready));
    }

    public function test_counterless_rar_capture_reaches_a_complete_volume_plan_with_all_terminal_evidence(): void
    {
        [$artifacts, $cache, $bundle] = $this->captured(true);
        $work = app(RecoveryWork::class);
        $work->enqueueForBundle(RecoveryStage::Discover, (int) $bundle->id, (int) $bundle->revision, 'prepare', []);
        $result = (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover));
        $this->assertSame('ready', $result);
        $row = DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->first();
        $plan = RecoveryPlan::fromArray(json_decode($row->sealed_plan, true));
        $this->assertSame(5, $plan->plannedFiles());
        $this->assertSame(12, $plan->plannedParts());
        $this->assertFalse($plan->multiMediaInventory());
        $this->assertCount(9, $plan->evidenceIds);
        $this->assertSame(4, DB::table('obfuscation_recovery_files')->whereNotNull('terminal_evidence')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }

    public function test_single_article_final_archive_volume_reuses_its_anchor_as_terminal_evidence(): void
    {
        [$artifacts, $cache, $bundle] = $this->captured(true, singleArticleFinalVolume: true);
        $work = app(RecoveryWork::class);
        $work->enqueueForBundle(RecoveryStage::Discover, (int) $bundle->id, (int) $bundle->revision, 'prepare', []);
        $this->assertSame('ready', (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover)));
        $row = DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->first();
        $plan = RecoveryPlan::fromArray(json_decode($row->sealed_plan, true));
        $this->assertSame(11, $plan->plannedParts());
        $this->assertCount(8, $plan->evidenceIds);
        $this->assertCount(8, json_decode($row->construction_targets, true));
        $last = DB::table('obfuscation_recovery_files')->where('bundle_id', $bundle->id)->where('archive_ordinal', 4)->first();
        $this->assertSame(json_decode($last->anchor_evidence, true)['message_id'], json_decode($last->terminal_evidence, true)['message_id']);
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }

    public function test_materialization_uses_actual_storage_and_detects_balanced_membership_corruption(): void
    {
        $this->createRecoveryCbpSchema();
        [$artifacts, $cache, $bundle] = $this->captured();
        $work = app(RecoveryWork::class);
        $work->enqueueForBundle(RecoveryStage::Discover, (int) $bundle->id, (int) $bundle->revision, 'prepare', []);
        (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover));
        $claim = $work->claim(RecoveryStage::Publish);
        $materialize = new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), $work);
        $this->assertSame('materialized', $materialize->run($claim));
        $this->assertSame('materialized', $materialize->run($claim));
        $this->assertSame(2, DB::table('binaries')->count());
        $this->assertSame(5, DB::table('parts')->count());
        $publication = DB::table('obfuscation_recovery_publications')->first();
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true));
        $verifier = new RecoveryCbpVerifier($artifacts);
        $this->assertSame((int) DB::table('parts')->sum('size'), $verifier->verify((int) $publication->id, $plan));
        DB::table('parts')->where('partnumber', 2)->update(['messageid' => 'substitution@fixture.invalid']);
        $this->expectExceptionMessage('recovery_part_membership_mismatch');
        $verifier->verify((int) $publication->id, $plan);
    }

    #[DataProvider('publicationProfiles')]
    public function test_actual_release_and_gzip_nzb_preserve_the_plan_after_cbp_cleanup_and_replay(bool $rar): void
    {
        $this->createRecoveryCbpSchema();
        $this->createRecoveryReleaseSchema();
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => (int) strtotime((string) $value));
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('recovery-nzb')]);
        [$artifacts, $cache, $bundle] = $this->captured($rar);
        $this->app->instance(RecoveryArtifacts::class, $artifacts);
        $work = app(RecoveryWork::class);
        $work->enqueueForBundle(RecoveryStage::Discover, (int) $bundle->id, (int) $bundle->revision, 'prepare', []);
        (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover));
        $claim = $work->claim(RecoveryStage::Publish);
        $materialize = new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), $work);
        $this->assertSame('materialized', $materialize->run($claim));
        $publication = DB::table('obfuscation_recovery_publications')->first();
        $creation = app(ReleaseCreationService::class);
        $this->assertSame(['added' => 0, 'dupes' => 0], $creation->createReleases(1, 10, false));
        $this->assertSame('created', $creation->createRecovered($claim, (int) $publication->id));
        $release = Release::query()->first();
        $this->assertSame(0, (int) $release->isrenamed);
        $this->assertFalse((bool) $release->is_trusted_name);
        $this->assertSame(0, (int) $release->declaredfiles);
        $this->assertSame($rar ? 5 : 2, (int) $release->totalpart);
        $this->assertSame($publication->guid, $release->guid);
        $nzb = app(NzbService::class);
        NzbCreationCandidateQuery::flushCapabilityCache();
        $result = $nzb->createNzbForRelease($release);
        $this->assertTrue($result->success, json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('parts')->count());
        if ($rar) {
            $published = DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->first();
            $sealed = RecoveryPlan::fromArray(json_decode($published->sealed_plan, true));
            $reader = new RecoveryCachedReader(new RecoveryManifest($artifacts), $cache);
            foreach ($sealed->files as $file) {
                if ($file->role === RecoveryFileRole::RarVolume) {
                    $head = $reader->read(new RecoveryArtifact($sealed->manifestDigest, $sealed->manifestBytes), $file);
                    $this->assertSame('partial_archive_listing', app(RecoveryArchiveInspection::class)->inspect($published, $file, $head));
                }
            }
            $observations = DB::table('obfuscation_recovery_files')->whereNotNull('contained_observations')->pluck('contained_observations');
            $this->assertCount(4, $observations);
            foreach ($observations as $json) {
                $listing = json_decode($json, true);
                $this->assertFalse($listing['complete']);
                $this->assertSame('partial_volume_listing', $listing['scope']);
                $this->assertSame('fixture.bin', base64_decode($listing['files'][0]['name']));
            }
            $this->assertFalse((bool) DB::table('obfuscation_recovery_publications')->value('multi_media_inventory'));
        }

        $this->assertSame('published', DB::table('obfuscation_recovery_publications')->value('state'));
        $this->assertSame('pending', DB::table('obfuscation_recovery_publications')->value('initialization_state'));
        $this->assertTrue($nzb->createNzbForRelease($release->fresh())->success);
        $this->assertSame('published', $materialize->run($claim));
        $this->assertSame(1, DB::table('releases')->count());
        $xml = simplexml_load_string($nzb->readNzbContents($release->guid));
        $this->assertCount($rar ? 5 : 2, $xml->file);
        $counts = [];
        foreach ($xml->file as $file) {
            $counts[] = count($file->segments->segment);
        }
        sort($counts);
        $this->assertSame($rar ? [1, 2, 3, 3, 3] : [1, 4], $counts);
        $repair = app(ReleaseRepairService::class)->repair($release, new ReleaseRepairOptions);
        $rescan = app(MissingFileRescanService::class)->rescan($release,
            new MissingFileRescanOptions, new RescanRunBudget(100));
        $this->assertSame(ReleaseRepairOutcome::UnsupportedRecoveryProfile, $repair->outcome);
        $this->assertSame(ReleaseRepairOutcome::UnsupportedRecoveryProfile, $rescan->outcome);
        $this->assertSame(0, $repair->articlesProbed + $rescan->articlesRequested);
        $this->assertSame('unsupported_recovery_profile', $release->fresh()->repair_outcome->value);
        $this->assertSame('unsupported_recovery_profile', $release->fresh()->rescan_outcome->value);
        $this->assertNull($release->fresh()->recovery_claimed_at);
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true));
        $restore = app(RecoveryNzbRestore::class);
        $this->assertSame('unchanged', $restore->run($release));
        $path = $nzb->nzbPath($release->guid);
        file_put_contents($path, gzencode('damaged'));
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 0]);
        $this->assertSame('restored', $restore->run($release));
        $digest = app(RecoveryNzbVerifier::class)->verify($path, $plan);
        unlink($path);
        $this->artisan('obfuscation:publish', ['--restore' => $release->id])->assertSuccessful();
        $this->assertSame($digest, app(RecoveryNzbVerifier::class)->verify($path, $plan));
        $old = RecoveryLease::acquire($release);
        $old->release();
        $new = RecoveryLease::acquire($release);
        $this->assertFalse($old->owns((int) $release->id));
        $this->assertTrue($new->owns((int) $release->id));
        $this->assertFalse($nzb->restoreRecoveryManifest($release, $old, $plan)->success);
        $old->release();
        $this->assertTrue($new->owns((int) $release->id));
        $new->release();
        $images = \Mockery::mock(ReleaseImageService::class);
        $images->shouldReceive('delete')->once()->with($release->guid);
        Search::shouldReceive('deleteRelease')->once()->with((int) $release->id);
        app(ReleaseManagementService::class)->deleteSingle(['g' => $release->guid, 'i' => $release->id], $nzb, $images);
        $this->assertSame('tombstoned', DB::table('obfuscation_recovery_publications')->value('state'));
        $this->assertSame('tombstoned', app(RecoveryPublications::class)->register($plan)->outcome);
        $this->assertSame('unavailable', $restore->run($release));
        $this->assertFalse(is_file($path));
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());

    }

    #[DataProvider('bundleSizes')]
    public function test_cached_bundle_naming_keeps_all_files_and_rejects_unscoped_parent_changes(int $count): void
    {
        $this->createRecoveryCbpSchema();
        $this->createRecoveryReleaseSchema();
        $this->createIdentificationSchema();
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => (int) strtotime((string) $value));
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('bundle-nzb'), 'nntmux_settings.add_par2' => true]);
        $files = [];
        foreach ($count === 7 ? [1, 2, 3, 4, 6, 7, 8] : range(1, $count) as $i => $episode) {
            $files[sprintf('Synthetic.Show.S01E%02d.1080p.mkv', $episode)] = "\x1a\x45\xdf\xa3\x8b\x42\x82\x88matroska"
                .SyntheticPosting::bytes('episode-'.$episode, 716800 * ($i + 2) + 100000 - 16);
        }
        [$artifacts, $cache, $bundle] = $this->captured(false, $files);
        $this->app->instance(RecoveryArtifacts::class, $artifacts);
        $this->app->instance(RecoveryEvidence::class, $cache);
        DB::table('settings')->insert(['name' => 'lookuppar2', 'value' => 1]);
        $work = app(RecoveryWork::class);
        $work->enqueueForBundle(RecoveryStage::Discover, (int) $bundle->id, (int) $bundle->revision, 'prepare', []);
        $this->assertSame('ready', (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover)));
        NzbCreationCandidateQuery::flushCapabilityCache();
        $this->artisan('obfuscation:publish', ['--limit' => 1])->assertSuccessful();
        $publication = DB::table('obfuscation_recovery_publications')->first();
        $this->assertSame('published', $publication->state);
        $release = Release::query()->first();
        $this->assertTrue(RecoveryReleaseGate::pending((int) $release->id));
        $this->assertSame('complete', app(RecoveryBootstrap::class)->run((int) $publication->id));
        $this->assertFalse(RecoveryReleaseGate::pending((int) $release->id));
        $this->assertSame($count === 7 ? 'Synthetic Show S01 Episodes 01-04,06-08 - 7 files - 1080p' : 'Synthetic Show S01 Episodes 01-32 - 32 files - 1080p', $release->fresh()->searchname);
        $this->assertFalse((bool) $release->fresh()->is_trusted_name);
        $this->assertSame($count, DB::table('release_files')->count());
        $this->assertSame($count, DB::table('par_hashes')->count());
        $this->assertSame($count, (int) $release->fresh()->rarinnerfilecount);
        $processor = new Par2Processor(app(NameFixingService::class), new Par2Info, true);
        $nntp = \Mockery::mock(NNTPService::class);
        $nntp->shouldNotReceive('getMessages');
        $this->assertTrue($processor->parseFromMessage($publication->index_message_id, (int) $release->id, 1, $nntp, 0));
        $this->assertSame($count, (int) $release->fresh()->rarinnerfilecount);
        $before = $release->fresh()->searchname;
        $updater = app(ReleaseUpdateService::class);
        $updater->updateRelease($release->fresh(), 'Synthetic.Show.S01E01.1080p', 'preDB: Match', true, 'PAR2, ', true, false, 123);
        $updater->attachPredbId((int) $release->id, 123);
        $this->assertSame($before, $release->fresh()->searchname);
        $this->assertSame(0, (int) $release->fresh()->predb_id);
        $this->assertFalse(Release::query()->whereRaw(RecoveryIdentityPolicy::singleItemSql())->exists());
        $provider = \Mockery::mock(AbstractTvProvider::class)->makePartial();
        $provider->setVideoIdFound(42, (int) $release->id, 43);
        $this->assertSame(0, (int) $release->fresh()->videos_id);
        $this->assertSame(0, (int) $release->fresh()->tv_episodes_id);
        $this->assertFalse(app(MovieService::class)->doMovieUpdate('tt12345678', 'fixture', (int) $release->id));
        $this->assertNull($release->fresh()->imdbid);
        Release::query()->whereKey($release->id)->update(['is_trusted_name' => true]);
        $this->assertSame([], app(NameFixingQueryService::class)->hashDonors(DB::table('par_hashes')->pluck('hash')->all()));
        Release::query()->whereKey($release->id)->update(['is_trusted_name' => false]);
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true));
        $fileIds = array_values(array_map(static fn ($file): string => $file->identity, array_filter($plan->files,
            static fn ($file): bool => $file->role === RecoveryFileRole::Media)));
        sort($fileIds, SORT_STRING);
        $heads = app(RecoveryHeads::class);
        $this->assertSame('head_available', $heads->read((int) $release->id, $fileIds[6], 1000)->outcome);
        $this->assertSame(0, DB::table('obfuscation_recovery_targets')->count());
        $head = $heads->read((int) $release->id, $fileIds[0]);
        $this->assertTrue($head->pending());
        $this->assertSame(16384, strlen($head->prefix->data));
        $this->assertSame(1, DB::table('obfuscation_recovery_targets')->count());
        $this->assertTrue(app(RecoveryHeads::class)->read((int) $release->id, $fileIds[0])->pending());
        $this->assertSame(1, DB::table('obfuscation_recovery_targets')->count());
        $this->assertSame('enrichment_file_limit', $heads->read((int) $release->id, $fileIds[2])->outcome);
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 0]);
        $this->assertSame('enrichment_disabled', $heads->read((int) $release->id, $fileIds[1])->outcome);
        $this->assertSame('head_available', $heads->read((int) $release->id, $fileIds[1], 1000)->outcome);
        $this->assertSame(1, DB::table('obfuscation_recovery_targets')->count());
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        $media = \Mockery::mock(MediaExtractionService::class);
        $media->shouldReceive('getMediaInfo')->andReturn(false);
        $this->app->instance(MediaExtractionService::class, $media);
        $downloads = \Mockery::mock(UsenetDownloadService::class)->makePartial();
        $downloads->shouldNotReceive('download');
        $downloads->shouldNotReceive('downloadByMessageIDs');
        $this->app->instance(UsenetDownloadService::class, $downloads);
        $result = app(ReleaseProcessor::class)->process(
            new ReleaseProcessingContext($release->fresh()), $this->makeTempDirectory('processing'));
        $this->assertSame(ProcessingOutcome::RecoveryEvidencePending, $result->outcome);
        $this->assertNotNull($result->nextAttemptAt);
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertTrue(RecoveryReleaseGate::pending((int) $release->id));
        $nntp->shouldNotReceive('getMessagesByMessageID');
        $nfo = app(NfoService::class);
        $contents = app(NzbContentsService::class);
        $contents->setNntp($nntp);
        $this->assertFalse($contents->getNfoFromNzb($release->guid, (int) $release->id, 1, 'alt.binaries.fixture'));
        $this->assertFalse($nfo->attemptNfoFromArchive($release->guid, (int) $release->id, $nntp));
        $this->assertSame('no_associated_nfo', DB::table('obfuscation_recovery_publications')->value('nfo_outcome'));
        $direct = new UsenetDownloadService(app(ProcessingConfiguration::class), $nntp);
        $targetId = DB::table('obfuscation_recovery_targets')->value('message_id');
        $this->assertSame('recovery_evidence_pending', $direct->downloadByMessageIDs([$targetId], releaseId: (int) $release->id)['error']);
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
        DB::table('settings')->where('name', 'lookuppar2')->update(['value' => 0]);
        Release::query()->whereKey($release->id)->update([...Release::searchNameValues('Recovered.fixture'), 'isrenamed' => 0]);
        DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update([
            'initialization_state' => 'pending', 'identity_outcome' => 'unresolved', 'enrichment_outcome' => null,
        ]);
        $this->assertSame('complete', app(RecoveryBootstrap::class)->run((int) $publication->id));
        $this->assertSame('Recovered.fixture', $release->fresh()->searchname);
        $this->assertSame('par2_naming_disabled', DB::table('obfuscation_recovery_publications')->value('identity_outcome'));
        $this->assertSame($count, DB::table('release_files')->count());
        $this->assertSame($count, DB::table('par_hashes')->count());
        $this->assertFalse(RecoveryReleaseGate::pending((int) $release->id));
        DB::table('obfuscation_recovery_evidence')->where('message_id_digest', hash('sha256', $publication->index_message_id))->delete();
        DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update(['initialization_state' => 'pending']);
        $this->assertSame('cached_index_unavailable', app(RecoveryBootstrap::class)->run((int) $publication->id));
        $this->assertFalse(RecoveryReleaseGate::pending((int) $release->id));
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());

    }

    /** @return array<string,array{int}> */
    public static function bundleSizes(): array
    {
        return ['seven with gaps' => [7], 'thirty-two' => [32]];
    }

    /** @return array<string,array{bool}> */
    public static function publicationProfiles(): array
    {
        return ['media' => [false], 'rar' => [true]];
    }

    public function test_missing_positive_capture_coverage_blocks_preparation_before_target_admission(): void
    {
        [$artifacts, $cache, $bundle] = $this->captured();
        DB::table('obfuscation_recovery_scans')->update(['complete' => false]);
        DB::table('obfuscation_recovery_coverage')->delete();
        $work = app(RecoveryWork::class);
        $work->enqueueForBundle(RecoveryStage::Discover, (int) $bundle->id, (int) $bundle->revision, 'prepare', []);
        $result = (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover));
        $this->assertSame('unknown_capture_gap', $result);
        $this->assertNull(DB::table('obfuscation_recovery_bundles')->value('sealed_plan'));
        $this->assertNull(DB::table('obfuscation_recovery_bundles')->value('construction_targets'));
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }

    /** @return array{RecoveryArtifacts,RecoveryEvidence,object} */
    public function test_multiple_contained_main_videos_revoke_archive_root_naming_and_cannot_be_renamed_from_volumes(): void
    {
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => (int) strtotime((string) $value));
        $this->createRecoveryCbpSchema();
        $this->createRecoveryReleaseSchema();
        $this->createIdentificationSchema();
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('archive-scope-nzb')]);
        [$artifacts, $cache, $bundle] = $this->captured(true);
        $this->app->instance(RecoveryArtifacts::class, $artifacts);
        $work = app(RecoveryWork::class);
        $work->enqueueForBundle(RecoveryStage::Discover, (int) $bundle->id, (int) $bundle->revision, 'prepare', []);
        (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover));
        $claim = $work->claim(RecoveryStage::Publish);
        (new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), $work))->run($claim);
        $publication = DB::table('obfuscation_recovery_publications')->first();
        app(ReleaseCreationService::class)->createRecovered($claim, (int) $publication->id);
        $release = Release::query()->first();
        $this->assertTrue(app(NzbService::class)->createNzbForRelease($release)->success);
        DB::table('releases')->where('id', $release->id)->update([...Release::searchNameValues('Incorrect.Movie.2024'), 'isrenamed' => 1, 'is_trusted_name' => true]);
        DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update(['identity_scope' => 'archive_set', 'identity_outcome' => 'identified']);
        $publication = DB::table('obfuscation_recovery_publications')->first();
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true));
        $file = array_values(array_filter($plan->files, fn ($file): bool => $file->role === RecoveryFileRole::RarVolume))[0];
        $one = SyntheticPosting::rar([1024], 'First.Movie.mkv')['volumes'][0];
        $two = SyntheticPosting::rar([1024], 'Second.Movie.mkv')['volumes'][0];
        $prefix = new RecoveryCachedPrefix(substr($one, 0, -7).substr($two, 20), false, []);
        $this->assertSame('partial_archive_listing', app(RecoveryArchiveInspection::class)->inspect($publication, $file, $prefix));
        $current = DB::table('obfuscation_recovery_publications')->first();
        $this->assertTrue((bool) $current->multi_media_inventory);
        $this->assertSame('contained_files', $current->identity_scope);
        $this->assertFalse((bool) $release->fresh()->is_trusted_name);
        $this->assertSame(0, (int) $release->fresh()->isrenamed);
        $this->assertStringStartsWith('Recovered.', $release->fresh()->searchname);
        $naming = \Mockery::mock(NameFixingService::class);
        $naming->shouldNotReceive('checkName');
        $inventory = (new RecoveryPar2)->parse($cache->get($publication->index_message_id)->data);
        $this->assertFalse((new RecoveryNaming)->apply($current, $inventory, $naming, true, false));
        $this->assertSame('contained_files', DB::table('obfuscation_recovery_publications')->value('identity_scope'));
    }

    private function createIdentificationSchema(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            foreach (['videos_id', 'tv_episodes_id', 'gamesinfo_id', 'proc_par2', 'rarinnerfilecount'] as $column) {
                $table->integer($column)->default(0);
            }
            foreach (['movieinfo_id', 'musicinfo_id', 'consoleinfo_id', 'bookinfo_id', 'anidbid'] as $column) {
                $table->integer($column)->nullable();
            }
            $table->string('imdbid')->nullable();
        });
        Schema::create('release_files', function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->string('name');
            $table->unsignedBigInteger('size');
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_at');
            $table->boolean('passworded');
            $table->string('crc32');
            $table->unique(['releases_id', 'name']);
        });
        Schema::create('par_hashes', function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->string('hash');
            $table->unique(['releases_id', 'hash']);
        });
    }

    public function test_cached_media_resolution_leaves_the_optional_file_allowance_for_later_unresolved_files(): void
    {
        $this->createRecoveryCbpSchema();
        $this->createRecoveryReleaseSchema();
        $this->createIdentificationSchema();
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => (int) strtotime((string) $value));
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('resolved-media-nzb'), 'nntmux_settings.add_par2' => true]);
        $files = [];
        foreach (range(1, 4) as $i) {
            $files['File'.$i.'.mkv'] = "\x1a\x45\xdf\xa3\x8b\x42\x82\x88matroska".SyntheticPosting::bytes('resolved-'.$i, 716800 * $i + 100000 - 16);
        }
        [$artifacts, $cache, $bundle] = $this->captured(false, $files);
        $this->app->instance(RecoveryArtifacts::class, $artifacts);
        $this->app->instance(RecoveryEvidence::class, $cache);
        $work = app(RecoveryWork::class);
        $work->enqueueForBundle(RecoveryStage::Discover, (int) $bundle->id, (int) $bundle->revision, 'prepare', []);
        $this->assertSame('ready', (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover)));
        NzbCreationCandidateQuery::flushCapabilityCache();
        $this->artisan('obfuscation:publish', ['--limit' => 1])->assertSuccessful();
        $ids = DB::table('obfuscation_recovery_files')->where('role', 'media')->orderBy('file_id')->pluck('file_id')->all();
        $media = \Mockery::mock(MediaExtractionService::class);
        $media->shouldReceive('getMediaInfo')->times(4)->andReturnUsing(function ($path) use ($ids): bool {
            $fileId = substr(basename($path), strlen('recovered-'), 32);
            if (in_array($fileId, array_slice($ids, 0, 2), true)) {
                DB::table('obfuscation_recovery_files')->where('file_id', $fileId)->update(['enrichment_outcome' => 'media_evidence_available']);

                return true;
            }

            return false;
        });
        $this->app->instance(MediaExtractionService::class, $media);
        $release = Release::query()->first();
        $this->app->forgetInstance(ReleaseProcessor::class);
        $result = app(ReleaseProcessor::class)->process(new ReleaseProcessingContext($release), $this->makeTempDirectory('resolved-media-inspection'));
        $this->assertSame(array_slice($ids, 0, 2), DB::table('obfuscation_recovery_files')->where('enrichment_outcome', 'media_evidence_available')->orderBy('file_id')->pluck('file_id')->all());
        $this->assertSame(ProcessingOutcome::RecoveryEvidencePending, $result->outcome);
        $this->assertSame([$ids[2]], DB::table('obfuscation_recovery_targets')->pluck('file_id')->all());
        $this->assertSame([$ids[2]], DB::table('obfuscation_recovery_files')->whereNotNull('enrichment_selected_at')->pluck('file_id')->all());
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }

    public static function containerInventories(): array
    {
        return [['mkv', 'mp4', false], ['mkv', 'mkv', false], ['mp4', 'mp4', false], ['mkv', 'mp4', true]];
    }

    #[DataProvider('containerInventories')]
    public function test_actual_media_inspection_retains_per_file_evidence_and_fences_parent_metadata(string $firstFormat, string $secondFormat, bool $tailMetadata): void
    {
        $this->createRecoveryCbpSchema();
        $this->createRecoveryReleaseSchema();
        $this->createIdentificationSchema();
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => (int) strtotime((string) $value));
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('media-nzb'), 'nntmux_settings.add_par2' => true,
            'nntmux_settings.mediainfo_path' => '/usr/bin/mediainfo']);
        $root = $this->makeTempDirectory('media-source');
        $mkv = file_get_contents(LocalMediaFixture::write($root, $firstFormat));
        $secondPath = LocalMediaFixture::write($root, $secondFormat, ! $tailMetadata, duration: 7);
        $mp4 = file_get_contents($secondPath);
        if ($tailMetadata) {
            $this->assertGreaterThan(2097152, LocalMediaFixture::mp4AtomOffsets($secondPath)['moov']);
        }
        $this->assertNotSame(intdiv(strlen($mkv) - 1, 716800), intdiv(strlen($mp4) - 1, 716800));
        [$artifacts, $cache, $bundle] = $this->captured(false, ['Generated.Show.S01E01.'.$firstFormat => $mkv, 'Generated.Show.S01E02.'.$secondFormat => $mp4]);
        $this->app->instance(RecoveryArtifacts::class, $artifacts);
        $this->app->instance(RecoveryEvidence::class, $cache);
        DB::table('settings')->insert(['name' => 'lookuppar2', 'value' => 0]);
        $work = app(RecoveryWork::class);
        $work->enqueueForBundle(RecoveryStage::Discover, (int) $bundle->id, (int) $bundle->revision, 'prepare', []);
        $this->assertSame('ready', (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover)));
        NzbCreationCandidateQuery::flushCapabilityCache();
        $this->artisan('obfuscation:publish', ['--limit' => 1])->assertSuccessful();
        $publication = DB::table('obfuscation_recovery_publications')->first();
        $this->assertSame('complete', app(RecoveryBootstrap::class)->run((int) $publication->id));
        $release = Release::query()->first();
        if ($tailMetadata) {
            DB::table('settings')->where('name', 'obfuscation_recovery_enrichment_enabled')->update(['value' => 0]);
        }
        $result = app(ReleaseProcessor::class)->process(new ReleaseProcessingContext($release->fresh()), $this->makeTempDirectory('media-inspection'));
        if ($tailMetadata) {
            $this->assertSame('metadata_outside_bounded_head', DB::table('obfuscation_recovery_files')->where('display_filename', 'Generated.Show.S01E02.mp4')->value('enrichment_outcome'));
        }
        $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
        $observations = DB::table('obfuscation_recovery_files')->whereNotNull('media_evidence')->pluck('media_evidence');
        $this->assertCount($tailMetadata ? 1 : 2, $observations);
        foreach ($observations as $json) {
            $observation = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('protected_file', $observation['scope']);
            $this->assertSame('bounded_head', $observation['completeness']);
            $this->assertSame(16384, $observation['observed_bytes']);
        }
        $this->assertSame(0, (int) $release->fresh()->videos_id);
        $this->assertSame(0, (int) $release->fresh()->tv_episodes_id);
        $this->assertNull($release->fresh()->movieinfo_id);
        $this->assertSame(0, DB::table('obfuscation_recovery_targets')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }

    private function captured(bool $rar = false, ?array $mediaFiles = null, bool $singleArticleFinalVolume = false): array
    {
        $this->travelTo(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $bytes = "\x1a\x45\xdf\xa3\x8b\x42\x82\x88matroska".SyntheticPosting::bytes('media', 2250400 - 16);
        $fixture = $rar ? RarPostingFixture::make($singleArticleFinalVolume) : MediaPostingFixture::make($mediaFiles ?? ['Feature.Fixture.2026.mkv' => $bytes]);
        if ($rar) {
            DB::table('usenet_groups')->where('id', 1)->update(['obfuscation_recovery_profile' => 'rar']);
        }
        $policy = new NeverBlacklistedService;
        $parsed = (new HeaderParser($policy))->parse($fixture['headers'], 'alt.binaries.fixture');
        $context = (new RecoveryControl)->begin(RecoveryConfig::fromSettings(), NntpProvider::fromConfig([
            'position' => 1, 'name' => 'fixture', 'host' => '127.0.0.1', 'port' => 119,
        ]), 1, 'alt.binaries.fixture', 4000000001, 4000000000 + count($fixture['headers']), HeaderScanDirection::Head, 1);
        $report = (new RecoveryCapture(RecoveryConfig::fromSettings(), $policy))->capture(new RecoveryCaptureBatch($fixture['headers'], $parsed['headers']), $context);
        $this->assertSame('captured', $report->outcome);
        $artifacts = new RecoveryArtifacts($this->makeTempDirectory('preparation'));
        $cache = new RecoveryEvidence($artifacts, new RecoveryIdentity);
        foreach ($fixture['cache'] as $id => $article) {
            $cache->store($id, $article);
        }
        $this->travel(121)->minutes();
        $runs = new RecoveryRunRefresh(new RecoveryRunDiscovery);
        while ($runs->step() !== null) {
        }
        $bundles = new RecoveryBundleRefresh(new RecoveryComponents);
        while ($bundles->step() !== null) {
        }
        $this->assertSame(1, DB::table('obfuscation_recovery_bundles')->count());

        return [$artifacts, $cache, DB::table('obfuscation_recovery_bundles')->first()];
    }
}
