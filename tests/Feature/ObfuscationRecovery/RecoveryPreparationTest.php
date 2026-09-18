<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Enums\HeaderScanDirection;
use App\Enums\ReleaseRepairOutcome;
use App\Facades\Search;
use App\Models\Category;
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
use App\Services\ObfuscationRecovery\RecoveryBudget;
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
use App\Services\ObfuscationRecovery\RecoveryDirty;
use App\Services\ObfuscationRecovery\RecoveryEvidence;
use App\Services\ObfuscationRecovery\RecoveryFileRole;
use App\Services\ObfuscationRecovery\RecoveryFrontierRebuild;
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
use App\Services\ObfuscationRecovery\RecoveryScanContext;
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
use App\Services\Search\SearchService;
use App\Services\TvProcessing\Providers\AbstractTvProvider;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
            $table->string('name')->unique();
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        (require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php'))->up();
        (require database_path('migrations/2026_09_13_190549_add_recovery_frontier_request_attribution.php'))->up();
        (require database_path('migrations/2026_09_14_110835_add_recovery_handoff_and_process_identity.php'))->up();
        (require database_path('migrations/2026_09_18_120000_bucket_obfuscation_recovery_dirty_marks.php'))->up();
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.fixture', 'obfuscation_recovery_profile' => 'media']);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[DataProvider('dirtyMinuteCases')]
    public function test_sealing_ignores_distant_dirty_minutes_but_waits_for_nearby_marks(int $offset, string $expected): void
    {
        [$artifacts, $cache, $bundle] = $this->captured();
        $header = (array) DB::table('obfuscation_recovery_headers')->first();
        $header['embedded_timestamp_ms'] = (int) $bundle->start_ms + $offset;
        // Media sealing must see nearby marks even in a different partition.
        $header['advertised_total'] = 999;
        RecoveryDirty::mark(DB::connection(), [$header]);
        $work = app(RecoveryWork::class);
        $result = (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover));
        $this->assertSame($expected, $result);
        $after = DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->first();
        if ($expected === 'ready') {
            $this->assertNotNull($after->sealed_plan);
        } else {
            $this->assertNull($after->sealed_plan);
            $this->assertSame('dirty_candidate_snapshot', $after->reason);
        }
    }

    public static function dirtyMinuteCases(): array
    {
        return ['two hours away' => [7200000, 'ready'], 'within margin' => [20000, 'dirty_candidate_snapshot']];
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

    #[DataProvider('verifiedMembershipCases')]
    public function test_unrelated_interior_media_total_preserves_verified_membership_through_capture_and_refresh(string $state, string $arrival, bool $existingAuxiliary = false): void
    {
        [$artifacts, $cache, $bundle] = $this->captured(existingAuxiliary: $existingAuxiliary);
        $work = app(RecoveryWork::class);
        $this->assertSame('ready', (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover)));
        DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->update(['state' => $state]);
        $before = DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->first();
        $policy = new NeverBlacklistedService;
        if ($arrival === 'bridge') {
            $neighbor = [['Subject' => '[x] - '.str_repeat('Q', 32).' yEnc (1/99)', 'From' => 'fixture@example.invalid',
                'Date' => 'Thu, 01 Jan 2026 00:00:00 +0000', 'Message-ID' => '<neighbor-'.((int) $before->end_ms + 60000).'@nyuu>',
                'Bytes' => 100, 'Number' => '4000000200', 'Xref' => '']];
            $parsed = (new HeaderParser($policy))->parse($neighbor, 'alt.binaries.fixture');
            $report = (new RecoveryCapture(RecoveryConfig::fromSettings(), $policy))->capture(new RecoveryCaptureBatch($neighbor, $parsed['headers']),
                new RecoveryScanContext(1, 'alt.binaries.fixture', $before->source_epoch, (int) $before->capture_generation,
                    4000000200, 4000000200, HeaderScanDirection::Head, (string) Str::uuid()));
            $this->assertSame('captured', $report->outcome);
            while (app(RecoveryRunRefresh::class)->step() !== null) {
            }
            while (app(RecoveryBundleRefresh::class)->step() !== null) {
            }
            $this->assertSame(2, DB::table('obfuscation_recovery_bundles')->where('kind', 'posting')->count());
        }
        for ($i = 0; $i < 2; $i++) {
            $stamp = (int) $before->start_ms + 50 + $i;
            $total = match ($arrival) {
                'selected' => 4, 'index' => 1, default => 99
            };
            $headers = [['Subject' => '[x] - '.str_repeat('Q', 32).' yEnc (1/'.$total.')', 'From' => 'fixture@example.invalid',
                'Date' => 'Thu, 01 Jan 2026 00:00:00 +0000', 'Message-ID' => '<aux-'.$stamp.'@nyuu>',
                'Bytes' => 100, 'Number' => (string) (4000000100 + $i), 'Xref' => '']];
            if ($arrival === 'identity') {
                $headers[0]['Number'] = (string) DB::table('obfuscation_recovery_headers')->where('advertised_total', 4)->value('article_number');
            }
            if ($arrival === 'bridge') {
                $headers[0]['Message-ID'] = '<bridge-'.((int) $before->end_ms + 30000).'@nyuu>';
            }
            if ($arrival === 'extension') {
                $headers[0]['Message-ID'] = '<extension-'.((int) $before->end_ms + 1000).'@nyuu>';
            }
            $parsed = (new HeaderParser($policy))->parse($headers, 'alt.binaries.fixture');
            $context = new RecoveryScanContext(1, 'alt.binaries.fixture', $before->source_epoch, (int) $before->capture_generation,
                (int) $headers[0]['Number'], (int) $headers[0]['Number'], HeaderScanDirection::Head, (string) Str::uuid());
            $report = (new RecoveryCapture(RecoveryConfig::fromSettings(), $policy))->capture(new RecoveryCaptureBatch($headers, $parsed['headers']), $context);
            $this->assertSame('captured', $report->outcome);
            if ($arrival !== 'unrelated') {
                $after = DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->first();
                if ($state === 'published') {
                    $this->assertSame('late_membership_conflict', $after->reason);
                } else {
                    $this->assertGreaterThan((int) $before->revision, (int) $after->revision);
                    $this->assertNull($after->sealed_plan);
                }

                return;
            }
            $this->assertEquals($before, DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->first());
            $duplicate = (new RecoveryCapture(RecoveryConfig::fromSettings(), $policy))->capture(new RecoveryCaptureBatch($headers, $parsed['headers']),
                new RecoveryScanContext(1, 'alt.binaries.fixture', $before->source_epoch, (int) $before->capture_generation,
                    (int) $headers[0]['Number'], (int) $headers[0]['Number'], HeaderScanDirection::Head, (string) Str::uuid()));
            $this->assertSame(0, $duplicate->captured);
            $this->assertEquals($before, DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->first());
            while (app(RecoveryRunRefresh::class)->step() !== null) {
            }
            while (app(RecoveryBundleRefresh::class)->step() !== null) {
            }
            $this->assertEquals($before, DB::table('obfuscation_recovery_bundles')->where('id', $bundle->id)->first());
        }
        $this->assertNotNull($work->claim(RecoveryStage::Publish));
    }

    public static function verifiedMembershipCases(): array
    {
        $cases = [];
        foreach (['ready', 'publishing', 'published'] as $state) {
            foreach (['unrelated', 'selected', 'index', 'identity', 'extension', 'bridge'] as $arrival) {
                $cases[] = [$state, $arrival];
                $cases[] = [$state, $arrival, true];
            }
        }

        return $cases;
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

    public function test_publication_coverage_transition_wakes_only_the_blocked_revision_and_preserves_later_local_backoff(): void
    {
        [$artifacts, $cache, $bundle] = $this->captured();
        $work = app(RecoveryWork::class);
        $this->assertSame('ready', (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover)));
        DB::table('obfuscation_recovery_frontiers')->update(['head_observed' => false]);
        $claim = $work->claim(RecoveryStage::Publish);
        $this->assertSame('publication_coverage_pending', (new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), $work))->run($claim));
        $future = DB::table('obfuscation_recovery_work')->where('id', $claim->id)->value('due_at');
        DB::table('obfuscation_recovery_frontiers')->update(['head_observed' => true]);
        app(RecoveryFrontierRebuild::class)->step();
        $this->assertLessThan($future, DB::table('obfuscation_recovery_work')->where('id', $claim->id)->value('due_at'));
        $next = $work->claim(RecoveryStage::Publish);
        $this->assertNotNull($next);
        $this->assertTrue($work->defer($next));
        $future = DB::table('obfuscation_recovery_work')->where('id', $claim->id)->value('due_at');
        for ($i = 0; $i < 3; $i++) {
            app(RecoveryFrontierRebuild::class)->step();
        }
        $this->assertSame($future, DB::table('obfuscation_recovery_work')->where('id', $claim->id)->value('due_at'));
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

    #[DataProvider('cachedNamingPolicies')]
    public function test_cached_inventory_naming_resolves_policy_without_display_files(?string $setting, bool $allowNaming = true, string $reader = 'bootstrap'): void
    {
        $this->createNamingSchema();
        $indexedNames = [];
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes()->andReturnUsing(static function (int $id) use (&$indexedNames): void {
            $indexedNames[] = Release::query()->whereKey($id)->value('searchname');
        });
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('naming-nzb'), 'nntmux_settings.add_par2' => false, 'nntmux.echocli' => false]);
        DB::table('settings')->updateOrInsert(['name' => 'fix_names'], ['value' => 1]);
        DB::table('settings')->where('name', 'lookuppar2')->delete();
        if ($setting !== null) {
            DB::table('settings')->insert(['name' => 'lookuppar2', 'value' => $setting]);
        }
        $filename = 'Synthetic.Feature.2026.1080p.BluRay.H264-Fixture.mkv';
        $bytes = "\x1a\x45\xdf\xa3\x8b\x42\x82\x88matroska".SyntheticPosting::bytes('naming-gate', 716800 * 2 + 100000 - 16);
        [$artifacts, $cache] = $this->captured(false, [$filename => $bytes]);
        $this->app->instance(RecoveryArtifacts::class, $artifacts);
        $this->app->instance(RecoveryEvidence::class, $cache);
        $work = app(RecoveryWork::class);
        $this->assertSame('ready', (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover)));
        NzbCreationCandidateQuery::flushCapabilityCache();
        $this->artisan('obfuscation:publish', ['--limit' => 1])->assertSuccessful();
        $publication = DB::table('obfuscation_recovery_publications')->first();
        if ($reader === 'historical') {
            DB::table('settings')->updateOrInsert(['name' => 'lookuppar2'], ['value' => 0]);
            $this->assertSame('complete', app(RecoveryBootstrap::class)->run((int) $publication->id));
            $this->assertSame('par2_naming_disabled', DB::table('obfuscation_recovery_publications')->value('identity_outcome'));
            Release::query()->whereKey($publication->releases_id)->update(['proc_par2' => 1, 'proc_files' => 1]);
            DB::table('settings')->where('name', 'lookuppar2')->delete();
            if ($setting !== null) {
                DB::table('settings')->insert(['name' => 'lookuppar2', 'value' => $setting]);
            }
            $this->artisan('obfuscation:publish', ['--limit' => 1])->assertSuccessful();
        } elseif (! $allowNaming) {
            $processor = new Par2Processor(app(NameFixingService::class), new Par2Info, false);
            $this->assertFalse($processor->parseData($cache->get($publication->index_message_id)->data, (int) $publication->releases_id, allowNaming: false));
        } elseif ($reader === 'nzb') {
            $nntp = \Mockery::mock(NNTPService::class);
            $nntp->shouldNotReceive('getMessages');
            $contents = new NzbContentsService(nntp: $nntp);
            $contents->parseNzb($publication->guid, (int) $publication->releases_id, 1);
        } else {
            $this->assertSame('complete', app(RecoveryBootstrap::class)->run((int) $publication->id));
        }
        $release = Release::query()->first();
        $enabled = $setting !== '0' && $allowNaming;
        $this->assertSame((int) $enabled, (int) $release->isrenamed);
        if ($enabled) {
            $this->assertSame('Synthetic.Feature.2026.1080p.BluRay.H264-Fixture', $release->searchname);
            $this->assertSame($release->searchname, end($indexedNames));
            $this->assertSame('identified', DB::table('obfuscation_recovery_publications')->value('identity_outcome'));
        } else {
            $this->assertStringStartsWith('Recovered.', $release->searchname);
            if ($reader !== 'nzb') {
                $this->assertSame('par2_naming_disabled', DB::table('obfuscation_recovery_publications')->value('identity_outcome'));
            }
        }
        $this->assertSame(0, DB::table('release_files')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
        if ($reader === 'historical') {
            $settled = DB::table('obfuscation_recovery_publications')->first();
            $path = app(NzbService::class)->getNzbPath($release->guid);
            $digest = hash_file('sha256', $path);
            $before = $release->getAttributes();
            $this->artisan('obfuscation:publish', ['--limit' => 10])->assertSuccessful();
            $this->assertSame($before, $release->fresh()->getAttributes());
            $this->assertEquals($settled, DB::table('obfuscation_recovery_publications')->first());
            $this->assertSame($digest, hash_file('sha256', $path));
            $this->assertSame(1, DB::table('obfuscation_recovery_publications')->count());
            $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
            $this->assertSame(1, (int) $release->fresh()->proc_files);
        }
    }

    public static function cachedNamingPolicies(): array
    {
        return ['missing' => [null], 'blank' => [''], 'enabled' => ['1'], 'disabled' => ['0'],
            'caller disabled' => ['1', false], 'nzb missing' => [null, true, 'nzb'],
            'nzb blank' => ['', true, 'nzb'], 'nzb disabled' => ['0', true, 'nzb'],
            'historical missing' => [null, true, 'historical']];
    }

    public function test_unresolved_historical_inventory_does_not_inherit_a_previous_naming_success(): void
    {
        $publication = $this->historicalNamingPublication(['opaque.mkv']);
        $release = Release::query()->findOrFail($publication->releases_id);
        $other = $release->replicate(['guid', 'collectionhash']);
        $other->guid = str_repeat('d', 32);
        $other->saveQuietly();
        $other->textstring = 'Recognized.Feature.2026.1080p.BluRay.H264-Fixture.mkv';
        $other->releases_id = $other->id;
        $naming = app(NameFixingService::class);
        $this->assertTrue($naming->checkName($other, true, 'PAR2, ', true, false));
        $this->assertSame(1, (int) $other->fresh()->isrenamed);

        $this->artisan('obfuscation:publish', ['--limit' => 1])->assertSuccessful();

        $this->assertSame('identity_unresolved', DB::table('obfuscation_recovery_publications')->value('identity_outcome'));
        $this->assertSame(0, (int) $release->fresh()->isrenamed);
        $settled = DB::table('obfuscation_recovery_publications')->first();
        $ledger = DB::table('obfuscation_recovery_attempts')->get()->all();
        $this->travel(10)->minutes();
        $this->artisan('obfuscation:publish', ['--limit' => 10])->assertSuccessful();
        $this->assertEquals($settled, DB::table('obfuscation_recovery_publications')->first());
        $this->assertEquals($ledger, DB::table('obfuscation_recovery_attempts')->get()->all());
    }

    public function test_direct_cached_naming_preserves_a_name_assigned_by_another_path(): void
    {
        $publication = $this->historicalNamingPublication(['Synthetic.Feature.2026.1080p.BluRay.H264-Fixture.mkv']);
        $release = Release::query()->findOrFail($publication->releases_id);
        $release->forceFill([...Release::searchNameValues('Operator.Chosen.Name'), 'isrenamed' => 1, 'is_trusted_name' => true])->saveQuietly();
        $before = $release->fresh()->getAttributes();
        $processor = new Par2Processor(app(NameFixingService::class), new Par2Info, false);
        $index = app(RecoveryEvidence::class)->get($publication->index_message_id);
        $processor->parseData($index->data, (int) $release->id);
        $this->assertSame($before, $release->fresh()->getAttributes());
        $this->assertSame('existing_name_preserved', DB::table('obfuscation_recovery_publications')->value('identity_outcome'));
    }

    #[DataProvider('historicalInventories')]
    public function test_historical_inventory_replays_with_its_original_identity_scope(array $names, bool $rar, ?string $expected, string $outcome): void
    {
        $publication = $this->historicalNamingPublication($names, $rar);
        $release = Release::query()->findOrFail($publication->releases_id);
        $ledger = DB::table('obfuscation_recovery_attempts')->get()->all();
        $path = app(NzbService::class)->getNzbPath($release->guid);
        $digest = hash_file('sha256', $path);
        $this->artisan('obfuscation:publish', ['--limit' => 1])->assertSuccessful();
        $current = DB::table('obfuscation_recovery_publications')->first();
        $this->assertSame($outcome, $current->identity_outcome);
        $this->assertSame($expected !== null, (bool) $release->fresh()->isrenamed);
        if ($expected !== null) {
            $this->assertSame($expected, $release->fresh()->searchname);
            $this->assertSame($outcome !== 'descriptive_bundle', (bool) $release->fresh()->is_trusted_name);
        }
        foreach (['identity', 'guid', 'sealed_plan', 'canonical_bundle_id', 'canonical_revision', 'releases_id', 'initialization_state'] as $field) {
            $this->assertSame($publication->{$field}, $current->{$field});
        }
        $this->assertSame(0, DB::table('release_files')->count());
        $this->artisan('obfuscation:publish', ['--limit' => 10])->assertSuccessful();
        $this->assertEquals($current, DB::table('obfuscation_recovery_publications')->first());
        $this->assertEquals($ledger, DB::table('obfuscation_recovery_attempts')->get()->all());
        $this->assertSame($digest, hash_file('sha256', $path));
        $this->assertSame(1, Release::query()->count());
    }

    public static function historicalInventories(): array
    {
        return [
            [['Synthetic.Feature.2026.1080p.BluRay.H264-Fixture.mkv'], false, 'Synthetic.Feature.2026.1080p.BluRay.H264-Fixture', 'identified'],
            [['Synthetic.Show.S01E01.1080p.mkv', 'Synthetic.Show.S01E02.1080p.mkv'], false, 'Synthetic Show S01 Episodes 01-02 - 2 files - 1080p', 'descriptive_bundle'],
            [[], true, 'Synthetic.Feature.2026.1080p.BluRay.H264-Fixture', 'identified'],
            [['First.Show.S01E01.1080p.mkv', 'Unrelated.Show.S01E02.1080p.mkv'], false, null, 'identity_unresolved'],
        ];
    }

    #[DataProvider('historicalNamingRefusals')]
    public function test_historical_naming_retry_preserves_policy_names_claims_and_accounting(string $condition, string $outcome): void
    {
        $publication = $this->historicalNamingPublication(['Synthetic.Feature.2026.1080p.BluRay.H264-Fixture.mkv']);
        $release = Release::query()->findOrFail($publication->releases_id);
        $path = app(NzbService::class)->getNzbPath($release->guid);
        $digest = hash_file('sha256', $path);
        $ledger = DB::table('obfuscation_recovery_attempts')->get()->all();
        $budgets = DB::table('obfuscation_recovery_budgets')->get()->all();
        $lease = null;
        if ($condition === 'missing_index') {
            DB::table('obfuscation_recovery_evidence')->where('message_id_digest', hash('sha256', $publication->index_message_id))->delete();
        } elseif ($condition === 'conflicting_index') {
            DB::table('obfuscation_recovery_evidence')->where('message_id_digest', hash('sha256', $publication->index_message_id))->update(['state' => 'conflict']);
        } elseif ($condition === 'manual_name' || $condition === 'already_named') {
            $release->forceFill([...Release::searchNameValues('Operator.Chosen.Name'), 'isrenamed' => (int) ($condition === 'already_named')])->saveQuietly();
        } elseif ($condition === 'trusted_name') {
            $release->forceFill(['is_trusted_name' => true])->saveQuietly();
        } elseif ($condition === 'predb_name') {
            $release->forceFill(['predb_id' => 123])->saveQuietly();
        } elseif ($condition === 'naming_disabled' || $condition === 'pane_disabled') {
            DB::table('settings')->updateOrInsert(['name' => $condition === 'naming_disabled' ? 'lookuppar2' : 'fix_names'], ['value' => 0]);
        } elseif ($condition === 'recovery_claim') {
            $lease = RecoveryLease::acquire($release);
            $this->assertNotNull($lease);
        } elseif ($condition === 'additional_claim') {
            $release->forceFill(['additional_pp_claimed_at' => now(), 'additional_pp_claim_token' => 'current-owner'])->saveQuietly();
        } elseif ($condition === 'release_deleted') {
            DB::table('releases')->where('id', $release->id)->delete();
        } elseif ($condition === 'publication_deleted') {
            DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update(['deleted_at' => now()]);
        } elseif ($condition === 'guid_changed') {
            $release->forceFill(['guid' => str_repeat('e', 32)])->saveQuietly();
        } elseif (in_array($condition, ['claim_lost', 'policy_changed', 'named_during_claim', 'policy_changed_after_cache', 'pane_changed_after_cache', 'pane_changed_after_second_cache'], true)) {
            $armed = true;
            $cacheReads = 0;
            DB::listen(function ($query) use (&$armed, &$cacheReads, $condition, $release): void {
                $afterCache = str_contains($condition, '_after_');
                $trigger = $afterCache ? 'select * from "obfuscation_recovery_evidence"' : 'update "releases" set "recovery_claimed_at" =';
                if (! $armed || ! str_starts_with($query->sql, $trigger)) {
                    return;
                }
                if ($afterCache && ! Release::query()->whereKey($release->id)->whereNotNull('recovery_claim_token')->exists()) {
                    return;
                }
                if ($afterCache && ++$cacheReads < ($condition === 'pane_changed_after_second_cache' ? 2 : 1)) {
                    return;
                }
                $armed = false;
                if ($condition === 'claim_lost') {
                    DB::table('releases')->where('id', $release->id)->update(['recovery_claim_token' => 'new-owner']);
                } elseif (in_array($condition, ['policy_changed', 'policy_changed_after_cache', 'pane_changed_after_cache', 'pane_changed_after_second_cache'], true)) {
                    DB::table('settings')->updateOrInsert(['name' => str_starts_with($condition, 'pane_changed_') ? 'fix_names' : 'lookuppar2'], ['value' => 0]);
                } else {
                    DB::table('releases')->where('id', $release->id)->update([...Release::searchNameValues('Concurrent.Manual.Name'), 'isrenamed' => 1]);
                }
            });
        }
        $before = $release->fresh()?->getAttributes();
        $this->artisan('obfuscation:publish', ['--limit' => 1])->assertSuccessful();
        if (isset($armed)) {
            $this->assertFalse($armed, 'The ownership or policy interruption must occur inside the real retry.');
        }
        $this->assertSame($outcome, DB::table('obfuscation_recovery_publications')->value('identity_outcome'));
        if (! in_array($condition, ['claim_lost', 'named_during_claim'], true)) {
            $this->assertSame($before, $release->fresh()?->getAttributes());
        } elseif ($condition === 'claim_lost') {
            $this->assertSame('new-owner', $release->fresh()->recovery_claim_token);
            $this->assertSame(0, (int) $release->fresh()->isrenamed);
        } else {
            $this->assertSame('Concurrent.Manual.Name', $release->fresh()->searchname);
        }
        $settled = DB::table('obfuscation_recovery_publications')->first();
        $this->artisan('obfuscation:publish', ['--limit' => 10])->assertSuccessful();
        $this->assertEquals($settled, DB::table('obfuscation_recovery_publications')->first());
        $this->assertEquals($ledger, DB::table('obfuscation_recovery_attempts')->get()->all());
        $this->assertEquals($budgets, DB::table('obfuscation_recovery_budgets')->get()->all());
        $this->assertSame($digest, hash_file('sha256', $path));
        $this->assertSame(0, DB::table('release_files')->count());
        $lease?->release();
    }

    #[DataProvider('bundlePolicyChanges')]
    public function test_historical_bundle_naming_rechecks_policy_after_final_inventory_validation(string $setting, bool $atMutation = false): void
    {
        $publication = $this->historicalNamingPublication(['Synthetic.Show.S01E01.1080p.mkv', 'Synthetic.Show.S01E02.1080p.mkv']);
        $release = Release::query()->findOrFail($publication->releases_id);
        $before = $release->getAttributes();
        Search::swap(\Mockery::spy(SearchService::class));
        $reads = 0;
        $changed = false;
        DB::listen(function ($query) use (&$reads, &$changed, $release, $setting, $atMutation): void {
            if ($changed) {
                return;
            }
            if (str_starts_with($query->sql, 'select * from "obfuscation_recovery_evidence"')
                && Release::query()->whereKey($release->id)->whereNotNull('recovery_claim_token')->exists()) {
                $reads++;
            }
            $atBoundary = $atMutation ? $query->sql === 'select * from "releases" where "id" = ? limit 1' : true;
            if ($reads === 3 && $atBoundary) {
                $changed = true;
                DB::table('settings')->updateOrInsert(['name' => $setting], ['value' => 0]);
            }
        });
        $this->artisan('obfuscation:publish', ['--limit' => 1])->assertSuccessful();
        $this->assertTrue($changed, 'Interrupt the actual cache validation or final locked release read.');
        $this->assertSame(3, $reads);
        Search::shouldNotHaveReceived('updateRelease');
        $this->assertSame($before, $release->fresh()->getAttributes());
        $this->assertSame('par2_naming_disabled', DB::table('obfuscation_recovery_publications')->value('identity_outcome'));
    }

    public static function bundlePolicyChanges(): array
    {
        return [['fix_names'], ['lookuppar2'], ['fix_names', true], ['lookuppar2', true]];
    }

    public static function historicalNamingRefusals(): array
    {
        return [
            ['missing_index', 'cached_index_unavailable'], ['conflicting_index', 'cached_identification_failed'],
            ['manual_name', 'existing_name_preserved'], ['already_named', 'par2_naming_disabled'],
            ['trusted_name', 'existing_name_preserved'], ['predb_name', 'existing_name_preserved'],
            ['naming_disabled', 'par2_naming_disabled'], ['pane_disabled', 'par2_naming_disabled'],
            ['recovery_claim', 'par2_naming_disabled'], ['additional_claim', 'par2_naming_disabled'],
            ['release_deleted', 'par2_naming_disabled'], ['publication_deleted', 'par2_naming_disabled'],
            ['guid_changed', 'par2_naming_disabled'], ['claim_lost', 'par2_naming_disabled'],
            ['policy_changed', 'par2_naming_disabled'], ['named_during_claim', 'existing_name_preserved'],
            ['policy_changed_after_cache', 'par2_naming_disabled'], ['pane_changed_after_cache', 'par2_naming_disabled'],
            ['pane_changed_after_second_cache', 'par2_naming_disabled'],
        ];
    }

    private function createNamingSchema(): void
    {
        $this->createRecoveryCbpSchema();
        $this->createRecoveryReleaseSchema();
        $this->createIdentificationSchema();
        Schema::create('predb', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->unique();
        });
        Schema::create('root_categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->boolean('generate_previews')->default(true);
        });
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => (int) strtotime((string) $value));
    }

    /** @param list<string> $names */
    private function historicalNamingPublication(array $names, bool $rar = false): object
    {
        $this->createNamingSchema();
        Schema::table('releases', function (Blueprint $table): void {
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->uuid('additional_pp_claim_token')->nullable();
        });
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('historical-naming-nzb'), 'nntmux_settings.add_par2' => false, 'nntmux.echocli' => false]);
        DB::table('settings')->updateOrInsert(['name' => 'fix_names'], ['value' => 1]);
        DB::table('settings')->updateOrInsert(['name' => 'lookuppar2'], ['value' => 0]);
        $files = [];
        foreach ($names as $i => $name) {
            $files[$name] = "\x1a\x45\xdf\xa3\x8b\x42\x82\x88matroska".SyntheticPosting::bytes('historical-'.$i, 716800 * ($i + 2) + 100000 - 16);
        }
        [$artifacts, $cache, $bundle] = $this->captured($rar, $files, archiveName: 'Synthetic.Feature.2026.1080p.BluRay.H264-Fixture');
        $this->app->instance(RecoveryArtifacts::class, $artifacts);
        $this->app->instance(RecoveryEvidence::class, $cache);
        $work = app(RecoveryWork::class);
        $this->assertSame('ready', (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover)));
        NzbCreationCandidateQuery::flushCapabilityCache();
        $this->artisan('obfuscation:publish', ['--limit' => 1])->assertSuccessful();
        $publication = DB::table('obfuscation_recovery_publications')->first();
        $this->assertSame('complete', app(RecoveryBootstrap::class)->run((int) $publication->id));
        $this->assertSame('par2_naming_disabled', DB::table('obfuscation_recovery_publications')->value('identity_outcome'));
        Release::query()->whereKey($publication->releases_id)->update(['proc_par2' => 1, 'proc_files' => 1]);
        $budget = app(RecoveryBudget::class);
        $reservation = $budget->reserve($bundle->owner_digest, 'construction', $publication->index_message_id, 131072, 20971520);
        $this->assertNotNull($reservation);
        $this->assertTrue($budget->settle($reservation, 150, 65536, 'success'));
        DB::table('settings')->where('name', 'lookuppar2')->delete();

        return DB::table('obfuscation_recovery_publications')->first();
    }

    #[DataProvider('recoveryForcedRoots')]
    public function test_recovery_formation_and_cached_inventory_naming_honor_all_group_forces(?int $primary, array $associated, int $expected, string $gate = '', bool $naming = true): void
    {
        $this->createRecoveryCbpSchema();
        $this->createRecoveryReleaseSchema();
        $this->createIdentificationSchema();
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => (int) strtotime((string) $value));
        $indexedCategories = [];
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes()->andReturnUsing(static function (int $id) use (&$indexedCategories): void {
            $indexedCategories[] = (int) Release::query()->whereKey($id)->value('categories_id');
        });
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('forced-nzb'), 'nntmux_settings.add_par2' => true]);
        $files = [];
        foreach ([1, 2] as $episode) {
            $files['Fixture.Show.S01E0'.$episode.'.1080p.mkv'] = "\x1a\x45\xdf\xa3\x8b\x42\x82\x88matroska"
                .SyntheticPosting::bytes('forced-'.$episode, 716800 * $episode + 100 - 16);
        }
        [$artifacts, $cache, $bundle] = $this->captured(false, $files);
        $this->app->instance(RecoveryArtifacts::class, $artifacts);
        $this->app->instance(RecoveryEvidence::class, $cache);
        DB::table('settings')->insert(['name' => 'lookuppar2', 'value' => (int) $naming]);
        DB::table('usenet_groups')->where('id', 1)->update(['forced_root_categories_id' => $primary]);
        foreach ([Category::MOVIE_OTHER, Category::TV_OTHER] as $category) {
            DB::table('categories')->insert(['id' => $category, 'title' => 'Fallback']);
        }
        if ($primary !== null || $associated !== []) {
            DB::table('categories')->where('id', Category::OTHER_MISC)->update(['status' => 0]);
        }
        $work = app(RecoveryWork::class);
        $this->assertSame('ready', (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover)));
        $claim = $work->claim(RecoveryStage::Publish);
        $this->assertSame('materialized', app(RecoveryMaterialization::class)->run($claim));
        $publication = DB::table('obfuscation_recovery_publications')->first();
        foreach ($associated as $i => $force) {
            DB::table('usenet_groups')->insert(['id' => $i + 2, 'name' => 'alt.fixture.'.$i, 'forced_root_categories_id' => $force]);
            DB::table('collection_groups')->insert(['collections_id' => $publication->collections_id, 'group_name' => 'alt.fixture.'.$i]);
        }
        if ($gate !== '') {
            DB::table('categories')->where('id', $expected)->update($gate === 'initial_category_disabled' ? ['status' => 0] : ['minsizetoformrelease' => PHP_INT_MAX]);
            $this->assertSame('policy_blocked', app(ReleaseCreationService::class)->createRecovered($claim, (int) $publication->id));
            $this->assertSame($gate, DB::table('obfuscation_recovery_publications')->value('reason'));
            $this->assertSame(0, Release::query()->count());

            return;
        }
        $this->assertSame('created', app(ReleaseCreationService::class)->createRecovered($claim, (int) $publication->id));
        $release = Release::query()->first();
        $this->assertSame($expected === Category::TV_OTHER && $primary === null && $associated === [] ? Category::OTHER_MISC : $expected, (int) $release->categories_id);
        $nzbResult = app(NzbService::class)->createNzbForRelease($release);
        $this->assertTrue($nzbResult->success, $nzbResult->reason);
        $this->assertSame('complete', app(RecoveryBootstrap::class)->run((int) $publication->id));
        $this->assertSame($expected, (int) $release->fresh()->categories_id);
        $this->assertFalse((bool) $release->fresh()->is_trusted_name);
        $this->assertSame(0, (int) $release->fresh()->videos_id);
        if ($naming) {
            $this->assertNotEmpty($indexedCategories);
            $this->assertSame($expected, end($indexedCategories));
            $this->assertStringContainsString('Fixture Show S01', $release->fresh()->searchname);
        } else {
            $this->assertStringStartsWith('Recovered.', $release->fresh()->searchname);
        }
        $this->assertSame('not_pending', app(RecoveryBootstrap::class)->run((int) $publication->id));
        $this->assertSame($expected, (int) $release->fresh()->categories_id);
    }

    public static function recoveryForcedRoots(): array
    {
        return [[Category::MOVIE_ROOT, [], Category::MOVIE_OTHER], [null, [Category::MOVIE_ROOT], Category::MOVIE_OTHER],
            [null, [Category::TV_ROOT, Category::MOVIE_ROOT], Category::MOVIE_OTHER],
            [Category::TV_ROOT, [Category::MOVIE_ROOT], Category::TV_OTHER], [null, [], Category::TV_OTHER],
            [Category::MOVIE_ROOT, [], Category::MOVIE_OTHER, '', false],
            [null, [Category::TV_ROOT], Category::TV_OTHER, '', false],
            [Category::MOVIE_ROOT, [], Category::MOVIE_OTHER, 'initial_category_disabled'],
            [null, [Category::MOVIE_ROOT], Category::MOVIE_OTHER, 'category_minimum_size']];
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

    #[DataProvider('archiveForces')]
    public function test_multiple_contained_main_videos_revoke_archive_root_naming_and_cannot_be_renamed_from_volumes(?int $force, bool $associated): void
    {
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => (int) strtotime((string) $value));
        $this->createRecoveryCbpSchema();
        $this->createRecoveryReleaseSchema();
        $this->createIdentificationSchema();
        $indexedCategories = [];
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes()->andReturnUsing(static function (int $id) use (&$indexedCategories): void {
            $indexedCategories[] = (int) Release::query()->whereKey($id)->value('categories_id');
        });
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('archive-scope-nzb')]);
        [$artifacts, $cache, $bundle] = $this->captured(true);
        if ($force !== null) {
            DB::table('categories')->insert(['id' => Category::TV_OTHER, 'title' => 'TV Other']);
            DB::table('usenet_groups')->where('id', 1)->update(['forced_root_categories_id' => $associated ? null : $force]);
        }
        $this->app->instance(RecoveryArtifacts::class, $artifacts);
        $work = app(RecoveryWork::class);
        $work->enqueueForBundle(RecoveryStage::Discover, (int) $bundle->id, (int) $bundle->revision, 'prepare', []);
        (new RecoveryPreparation($cache, $artifacts, $work))->run($work->claim(RecoveryStage::Discover));
        $claim = $work->claim(RecoveryStage::Publish);
        (new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), $work))->run($claim);
        $publication = DB::table('obfuscation_recovery_publications')->first();
        app(ReleaseCreationService::class)->createRecovered($claim, (int) $publication->id);
        $release = Release::query()->first();
        if ($associated) {
            DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.fixture.forced', 'forced_root_categories_id' => $force]);
            DB::table('releases_groups')->insert(['releases_id' => $release->id, 'groups_id' => 2]);
        }
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
        $this->assertSame($force === null ? Category::OTHER_MISC : Category::TV_OTHER, (int) $release->fresh()->categories_id);
        $this->assertNotEmpty($indexedCategories);
        $this->assertSame($force === null ? Category::OTHER_MISC : Category::TV_OTHER, end($indexedCategories));
        $naming = \Mockery::mock(NameFixingService::class);
        $naming->shouldNotReceive('checkName');
        $inventory = (new RecoveryPar2)->parse($cache->get($publication->index_message_id)->data);
        $this->assertFalse((new RecoveryNaming)->apply($current, $inventory, $naming, true, false));
        $this->assertSame('contained_files', DB::table('obfuscation_recovery_publications')->value('identity_scope'));
    }

    public static function archiveForces(): array
    {
        return [[null, false], [Category::TV_ROOT, false], [Category::TV_ROOT, true]];
    }

    private function createIdentificationSchema(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            foreach (['videos_id', 'tv_episodes_id', 'gamesinfo_id', 'proc_par2', 'proc_files', 'rarinnerfilecount'] as $column) {
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

    private function captured(bool $rar = false, ?array $mediaFiles = null, bool $singleArticleFinalVolume = false, bool $existingAuxiliary = false, string $archiveName = 'Fixture'): array
    {
        $this->travelTo(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $bytes = "\x1a\x45\xdf\xa3\x8b\x42\x82\x88matroska".SyntheticPosting::bytes('media', 2250400 - 16);
        $fixture = $rar ? RarPostingFixture::make($singleArticleFinalVolume, $archiveName) : MediaPostingFixture::make($mediaFiles ?? ['Feature.Fixture.2026.mkv' => $bytes]);
        if ($existingAuxiliary) {
            $right = array_pop($fixture['headers']);
            $fixture['headers'][] = ['Subject' => '[x] - '.str_repeat('Q', 32).' yEnc (1/99)', 'From' => 'fixture@example.invalid',
                'Date' => 'Thu, 01 Jan 2026 00:00:00 +0000', 'Message-ID' => '<existing-1767225600050@nyuu>',
                'Bytes' => 100, 'Number' => (string) (4000000001 + count($fixture['headers'])), 'Xref' => ''];
            $right['Number'] = (string) (4000000001 + count($fixture['headers']));
            $fixture['headers'][] = $right;
        }
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
