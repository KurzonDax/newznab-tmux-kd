<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReleaseRepairOutcome;
use App\Facades\Search;
use App\Models\Release;
use App\Services\Binaries\BinariesConfig;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\IncompleteReleaseSweepQuery;
use App\Services\Releases\ReleaseManagementService;
use App\Support\Data\NzbCreationResult;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Psr\Log\AbstractLogger;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class NzbCreationReliabilityTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private string $tempNzbPath = '';

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '0', 'innerfileblacklist' => ''];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        $this->tempNzbPath = sys_get_temp_dir().'/nntmux-nzb-reliability-'.uniqid('', true);
        mkdir($this->tempNzbPath, 0775, true);
        config(['nntmux_settings.path_to_nzbs' => $this->tempNzbPath]);
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => strtotime((string) $value));
        DB::statement('PRAGMA foreign_keys = ON');

        $this->createSchema();
        $this->seedSettings();
        NzbCreationCandidateQuery::flushCapabilityCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        NzbCreationCandidateQuery::flushCapabilityCache();
        $this->deleteDirectory($this->tempNzbPath);
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_candidate_query_skips_active_claims_and_recovers_stale_claims(): void
    {
        $this->insertRelease(1, 'a', claimedAt: now(), postdate: '2026-07-13 10:00:00');
        $this->insertRelease(2, 'b', claimedAt: now()->subSeconds(301), postdate: '2026-07-13 09:00:00');
        $this->insertRelease(3, 'c', postdate: '2026-07-13 08:00:00');

        $claimed = NzbCreationCandidateQuery::claimBatch(null, 10, 'token-one', ['id']);

        $this->assertSame([2, 3], $claimed->pluck('id')->all());
        $this->assertSame('token-one', DB::table('releases')->where('id', 2)->value('nzb_creation_claim_token'));
        $this->assertSame('claimed', DB::table('releases')->where('id', 1)->value('nzb_creation_claim_token'));
    }

    public function test_each_release_lease_is_refreshed_immediately_before_processing(): void
    {
        Carbon::setTestNow('2026-07-13 12:00:00');
        $this->insertRelease(1, 'a', postdate: '2026-07-13 10:00:00');
        $this->insertRelease(2, 'b', postdate: '2026-07-13 09:00:00');
        $nzb = new LeaseObservingNzbService;
        $service = (new ReleaseProcessingService(
            nzb: $nzb,
            releaseManagement: new DatabaseOnlyReleaseManagementService,
            collectionCleanupService: app(CollectionCleanupService::class),
        ))->setEchoCLI(false);

        $this->assertSame(2, $service->createNZBs(null));

        $this->assertSame([
            '2026-07-13 12:00:00',
            '2026-07-13 12:02:00',
        ], $nzb->observedClaimTimes);
    }

    public function test_lease_refresh_cannot_extend_a_reclaimed_token(): void
    {
        Carbon::setTestNow('2026-07-13 12:00:00');
        $this->insertRelease(1, 'a', claimedAt: now());
        DB::table('releases')->where('id', 1)->update([
            'nzb_creation_claimed_at' => '2026-07-13 12:01:00',
            'nzb_creation_claim_token' => 'worker-two',
        ]);
        Carbon::setTestNow('2026-07-13 12:02:00');

        $this->assertFalse(NzbCreationCandidateQuery::refreshClaim(1, 'worker-one'));
        $this->assertSame('2026-07-13 12:01:00', DB::table('releases')->where('id', 1)->value('nzb_creation_claimed_at'));
        $this->assertSame('worker-two', DB::table('releases')->where('id', 1)->value('nzb_creation_claim_token'));
    }

    public function test_deterministic_creation_failure_deletes_release_and_cbp_rows(): void
    {
        $coversRoot = $this->makeTempDirectory('nzb-failure-covers');
        config(['nntmux_settings.covers_path' => $coversRoot]);
        $this->insertRelease(1, 'a');
        $this->insertCbp(200, 2000, 1);

        $guid = str_repeat('a', 36);
        $nzb = new FakeNzbCreationService(
            NzbCreationResult::deterministic('Collection has invalid data.', [200])
        );
        $releaseImage = new ReleaseImageService;
        $artifacts = [
            $nzb->getNzbPath($guid, 1, true),
            $releaseImage->vidSavePath.$guid.'.ogv',
            $releaseImage->audSavePath.$guid.'.mp3',
            $releaseImage->audSavePath.$guid.'_spectrum.png',
        ];
        foreach ($artifacts as $path) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, 'delete me');
        }
        Search::shouldReceive('deleteRelease')->once()->with(1);

        $service = (new ReleaseProcessingService(
            nzb: $nzb,
            releaseManagement: new ReleaseManagementService,
            releaseImage: $releaseImage,
            collectionCleanupService: app(CollectionCleanupService::class),
        ))->setEchoCLI(false);

        $this->assertSame(0, $service->createNZBs(null));
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('parts')->count());
        foreach ($artifacts as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_deterministic_failure_after_another_worker_succeeds_deletes_nothing(): void
    {
        $this->insertRelease(1, 'p');
        $this->insertWritableCbp(200, 2000, 1);
        $nzb = new WinningDuringFailureNzbService;
        $service = (new ReleaseProcessingService(
            nzb: $nzb,
            releaseManagement: new DatabaseOnlyReleaseManagementService,
            collectionCleanupService: app(CollectionCleanupService::class),
        ))->setEchoCLI(false);

        $this->assertSame(0, $service->createNZBs(null));

        $this->assertSame(1, (int) DB::table('releases')->where('id', 1)->value('nzbstatus'));
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(1, DB::table('parts')->count());
        $this->assertSame('winner-final', unzipGzipFile($nzb->winnerPath));
    }

    public function test_transient_creation_failure_records_retry_before_threshold(): void
    {
        Log::partialMock();
        $nzbCreationLogger = new RecordingNzbCreationLogger;
        Log::shouldReceive('channel')
            ->once()
            ->with('nzb_creation')
            ->andReturn($nzbCreationLogger);

        $this->insertRelease(1, 'a', attempts: 0);
        $this->insertCbp(200, 2000, 1);

        $service = $this->releaseProcessingService(
            NzbCreationResult::transient('Temporary filesystem failure.', [200])
        );

        $this->assertSame(0, $service->createNZBs(null));
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(1, (int) DB::table('release_nzb_creation_failures')->where('releases_id', 1)->value('attempts'));
        $this->assertSame('Temporary filesystem failure.', DB::table('release_nzb_creation_failures')->where('releases_id', 1)->value('last_error'));
        $this->assertNotNull(DB::table('release_nzb_creation_failures')->where('releases_id', 1)->value('created_at'));
        $this->assertNotNull(DB::table('release_nzb_creation_failures')->where('releases_id', 1)->value('updated_at'));
        $this->assertNotNull(DB::table('releases')->where('id', 1)->value('nzb_creation_claimed_at'));
        $this->assertNotNull(DB::table('releases')->where('id', 1)->value('nzb_creation_claim_token'));
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame('NZB creation failed; release will be retried', $nzbCreationLogger->warnings[0]['message']);
        $this->assertSame(1, $nzbCreationLogger->warnings[0]['context']['release_id']);
        $this->assertSame(str_repeat('a', 36), $nzbCreationLogger->warnings[0]['context']['guid']);
        $this->assertSame(NzbCreationResult::FAILURE_TRANSIENT, $nzbCreationLogger->warnings[0]['context']['failure_type']);
        $this->assertSame('Temporary filesystem failure.', $nzbCreationLogger->warnings[0]['context']['reason']);
        $this->assertSame(1, $nzbCreationLogger->warnings[0]['context']['next_attempt']);
        $this->assertSame(3, $nzbCreationLogger->warnings[0]['context']['max_attempts']);
    }

    public function test_transient_failure_after_reclaim_does_not_write_failure_state(): void
    {
        $this->insertRelease(1, 'q');
        $this->insertCbp(200, 2000, 1);
        $service = (new ReleaseProcessingService(
            nzb: new ReclaimedDuringTransientFailureNzbService,
            releaseManagement: new DatabaseOnlyReleaseManagementService,
            collectionCleanupService: app(CollectionCleanupService::class),
        ))->setEchoCLI(false);

        $this->assertSame(0, $service->createNZBs(null));

        $this->assertSame(0, DB::table('release_nzb_creation_failures')->count());
        $this->assertSame('worker-two', DB::table('releases')->where('id', 1)->value('nzb_creation_claim_token'));
        $this->assertSame(1, DB::table('collections')->count());
    }

    public function test_third_transient_creation_failure_deletes_release_and_cbp_rows(): void
    {
        Log::partialMock();
        $nzbCreationLogger = new RecordingNzbCreationLogger;
        Log::shouldReceive('channel')
            ->once()
            ->with('nzb_creation')
            ->andReturn($nzbCreationLogger);

        $this->insertRelease(1, 'a', attempts: 2);
        $this->insertCbp(200, 2000, 1);

        $service = $this->releaseProcessingService(
            NzbCreationResult::transient('Repeated filesystem failure.', [200])
        );

        $this->assertSame(0, $service->createNZBs(null));
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(0, DB::table('release_nzb_creation_failures')->count());
        $this->assertSame('Deleting release after NZB creation failure', $nzbCreationLogger->warnings[0]['message']);
        $this->assertSame(1, $nzbCreationLogger->warnings[0]['context']['release_id']);
        $this->assertSame(str_repeat('a', 36), $nzbCreationLogger->warnings[0]['context']['guid']);
        $this->assertSame(NzbCreationResult::FAILURE_TRANSIENT, $nzbCreationLogger->warnings[0]['context']['failure_type']);
        $this->assertSame('Repeated filesystem failure.', $nzbCreationLogger->warnings[0]['context']['reason']);
        $this->assertSame(3, $nzbCreationLogger->warnings[0]['context']['attempt']);
        $this->assertSame(3, $nzbCreationLogger->warnings[0]['context']['max_attempts']);
    }

    public function test_repeated_transient_failure_increments_attempts_and_touches_updated_at(): void
    {
        Log::partialMock();
        Log::shouldReceive('channel')->once()->with('nzb_creation')->andReturn(new RecordingNzbCreationLogger);

        $this->insertRelease(1, 'a');
        $this->insertCbp(200, 2000, 1);
        DB::table('release_nzb_creation_failures')->insert([
            'releases_id' => 1,
            'attempts' => 1,
            'last_error' => 'Earlier failure.',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $service = $this->releaseProcessingService(
            NzbCreationResult::transient('Another filesystem failure.', [200])
        );

        $this->assertSame(0, $service->createNZBs(null));

        $failure = DB::table('release_nzb_creation_failures')->where('releases_id', 1)->first();

        $this->assertSame(2, (int) $failure->attempts);
        $this->assertSame('Another filesystem failure.', $failure->last_error);
        $this->assertSame('2026-01-01 00:00:00', $failure->created_at);
        $this->assertNotSame('2026-01-01 00:00:00', $failure->updated_at);
    }

    public function test_attempt_cap_holds_when_the_failure_relation_was_dropped(): void
    {
        Log::partialMock();
        $nzbCreationLogger = new RecordingNzbCreationLogger;
        Log::shouldReceive('channel')->once()->with('nzb_creation')->andReturn($nzbCreationLogger);

        $this->insertRelease(1, 'a', attempts: 2);
        $this->insertCbp(200, 2000, 1);

        $service = (new ReleaseProcessingService(
            nzb: new RelationDroppingNzbService(NzbCreationResult::transient('Repeated filesystem failure.', [200])),
            releaseManagement: new DatabaseOnlyReleaseManagementService,
            collectionCleanupService: app(CollectionCleanupService::class),
        ))->setEchoCLI(false);

        $this->assertSame(0, $service->createNZBs(null));
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame('Deleting release after NZB creation failure', $nzbCreationLogger->warnings[0]['message']);
        $this->assertSame(3, $nzbCreationLogger->warnings[0]['context']['attempt']);
    }

    public function test_writer_classifies_final_rename_failure_as_transient_without_final_file(): void
    {
        $this->insertRelease(1, 'd');
        $this->insertWritableCbp(200, 2000, 1);

        $nzb = new RenameFailingNzbService(app(CollectionCleanupService::class));
        $result = $this->writeNzb(1, $nzb);

        $this->assertFalse($result->success);
        $this->assertTrue($result->isTransientFailure());
        $this->assertStringContainsString('move temporary NZB into place', $result->reason);
        $this->assertFileDoesNotExist($nzb->getNzbPath((string) DB::table('releases')->where('id', 1)->value('guid')));
        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('nzbstatus'));
    }

    public function test_writer_that_lost_its_claim_cannot_finalize_or_overwrite_the_winners_nzb(): void
    {
        $this->insertRelease(1, 'o', claimedAt: now(), completion: 91.67);
        $this->insertWritableCbp(200, 2000, 1, totalParts: 3, arrivedParts: 3);
        $release = Release::query()->findOrFail(1);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);
        DB::table('releases')->where('id', 1)->update([
            'nzb_creation_claim_token' => 'worker-two',
            'nzb_creation_claimed_at' => now(),
        ]);
        $nzb = new NzbService(app(CollectionCleanupService::class));
        $path = $nzb->getNzbPath((string) $release->guid, 0, true);
        file_put_contents($path, gzencode('worker-two-final'));

        $result = $nzb->createNzbForRelease($release);

        $this->assertTrue($result->isClaimLost());
        $this->assertSame('worker-two-final', unzipGzipFile($path));
        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('nzbstatus'));
        $this->assertSame('worker-two', DB::table('releases')->where('id', 1)->value('nzb_creation_claim_token'));
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(3, DB::table('parts')->count());
        $this->assertSame(91.67, $this->completionFor(1));
        $this->assertSame([], glob($path.'.tmp.*') ?: []);
    }

    public function test_writer_streams_multiple_keyset_pages_in_segment_order(): void
    {
        $this->insertRelease(1, 'h');
        DB::table('release_nzb_creation_failures')->insert([
            'releases_id' => 1,
            'attempts' => 1,
            'last_error' => 'Earlier failure.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertWritableCbp(200, 2000, 1);
        DB::table('parts')->where('binaries_id', 2000)->delete();
        DB::table('parts')->insert([
            ['binaries_id' => 2000, 'messageid' => '<part02@example.test>', 'partnumber' => 2, 'number' => 2, 'size' => 200],
            ['binaries_id' => 2000, 'messageid' => '<part01@example.test>', 'partnumber' => 1, 'number' => 1, 'size' => 100],
            ['binaries_id' => 2000, 'messageid' => '<part03@example.test>', 'partnumber' => 3, 'number' => 3, 'size' => 300],
        ]);
        $result = $this->writeNzb(1, new NzbService(app(CollectionCleanupService::class), new BinariesConfig(nzbStreamRows: 2)));

        $this->assertTrue($result->success, $result->reason);
        $xml = unzipGzipFile((string) $result->path);
        $this->assertIsString($xml);
        $this->assertLessThan(strpos($xml, 'part02@example.test'), strpos($xml, 'part01@example.test'));
        $this->assertLessThan(strpos($xml, 'part03@example.test'), strpos($xml, 'part02@example.test'));
        $this->assertStringContainsString('<group>alt.test</group>', $xml);
        $this->assertSame(0, DB::table('release_nzb_creation_failures')->count());
    }

    public function test_reclaim_during_stream_cannot_replace_the_winners_nzb_or_delete_cbp(): void
    {
        $this->insertRelease(1, 'r', claimedAt: now(), completion: 91.67);
        $this->insertWritableCbp(200, 2000, 1, totalParts: 3, arrivedParts: 3);
        $staleRelease = Release::query()->findOrFail(1);
        $staleToken = (string) $staleRelease->getAttribute(NzbCreationCandidateQuery::CLAIM_TOKEN_COLUMN);
        DB::table('releases')->where('id', 1)->update([
            'nzb_creation_claim_token' => 'worker-two',
            'nzb_creation_claimed_at' => now(),
        ]);
        $winnerRelease = Release::query()->findOrFail(1);
        $winnerRelease->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);
        $nzb = new NzbService(app(CollectionCleanupService::class), new BinariesConfig(nzbStreamRows: 2));
        $staleWorker = (new ReleaseProcessingService(
            nzb: $nzb,
            releaseManagement: new DatabaseOnlyReleaseManagementService,
            collectionCleanupService: app(CollectionCleanupService::class),
        ))->setEchoCLI(false);
        $deleteMethod = new \ReflectionMethod(ReleaseProcessingService::class, 'deleteFailedNzbCreationRelease');
        $cleanupAttempted = false;
        $staleDeleteResult = null;
        $cbpCountsAfterStaleCleanup = [];
        DB::listen(function (QueryExecuted $query) use (
            &$cleanupAttempted,
            &$staleDeleteResult,
            &$cbpCountsAfterStaleCleanup,
            $deleteMethod,
            $staleWorker,
            $staleRelease,
            $staleToken,
        ): void {
            if ($cleanupAttempted || ! str_contains($query->sql, 'SELECT b.collections_id AS collection_id')) {
                return;
            }

            $cleanupAttempted = true;
            $staleDeleteResult = $deleteMethod->invoke(
                $staleWorker,
                $staleRelease,
                NzbCreationResult::deterministic('The stale worker saw empty CBP.', [200]),
                $staleToken,
                1,
            );
            $cbpCountsAfterStaleCleanup = [
                DB::table('collections')->count(),
                DB::table('binaries')->count(),
                DB::table('parts')->count(),
            ];
        });

        $result = $nzb->createNzbForRelease($winnerRelease);

        $this->assertTrue($cleanupAttempted);
        $this->assertFalse($staleDeleteResult);
        $this->assertSame([1, 1, 3], $cbpCountsAfterStaleCleanup);
        $this->assertTrue($result->success, $result->reason);
        $xml = unzipGzipFile((string) $result->path);
        $this->assertIsString($xml);
        $this->assertStringContainsString('binary2000-part1@example.test', $xml);
        $this->assertStringContainsString('binary2000-part2@example.test', $xml);
        $this->assertStringContainsString('binary2000-part3@example.test', $xml);
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('parts')->count());
    }

    public function test_writer_reconciles_creation_time_completion_with_the_cbp_it_writes(): void
    {
        // A late part arrived after release creation measured 91.67%, so the authoritative CBP
        // measurement at write time must replace that stale value with the complete result.
        $this->insertRelease(1, 'i', completion: 91.67);
        $this->insertWritableCbp(200, 2000, 1, totalParts: 3, arrivedParts: 3);

        $result = $this->writeNzb(1);

        $this->assertTrue($result->success, $result->reason);
        $this->assertSame(100.0, $this->completionFor(1));
    }

    public function test_a_release_measured_sub_threshold_waits_for_the_repair_engine(): void
    {
        $this->insertRelease(1, 'j', completion: (107 / 311) * 100);
        $this->insertWritableCbp(200, 2000, 1, totalParts: 211, arrivedParts: 7);
        $this->insertWritableBinary(200, 2001, 'Example.Release.part02.rar yEnc', totalParts: 100, arrivedParts: 100);

        $result = $this->writeNzb(1);

        $this->assertTrue($result->success, $result->reason);
        $this->assertEqualsWithDelta((107 / 311) * 100, $this->completionFor(1), 0.0001);

        // Measured sub-threshold, but the repair engine has not seen it yet: the sweep waits.
        // Missing headers usually mean articles that are still on the provider.
        $this->assertSame([], $this->releasesSelectedByCompletionCleanup(95.0));

        DB::table('releases')->where('id', 1)->update(['repair_outcome' => ReleaseRepairOutcome::Failed->value]);
        $this->assertSame([1], $this->releasesSelectedByCompletionCleanup(95.0));
    }

    public function test_writer_leaves_the_never_measured_sentinel_alone(): void
    {
        $this->insertRelease(1, 'l');
        $this->insertWritableCbp(200, 2000, 1, totalParts: 0, arrivedParts: 4);

        $result = $this->writeNzb(1);

        $this->assertTrue($result->success, $result->reason);
        $this->assertSame(0.0, $this->completionFor(1));
        $this->assertSame([], $this->releasesSelectedByCompletionCleanup(95.0));
    }

    public function test_writer_streams_every_page_when_binaries_span_pages(): void
    {
        $this->insertRelease(1, 'm');
        $this->insertWritableCbp(200, 2000, 1, totalParts: 10, arrivedParts: 5);
        $this->insertWritableBinary(200, 2001, 'Example.Release.part02.rar yEnc', totalParts: 10, arrivedParts: 5);

        $result = $this->writeNzb(1, new NzbService(app(CollectionCleanupService::class), new BinariesConfig(nzbStreamRows: 3)));

        $this->assertTrue($result->success, $result->reason);
        $xml = unzipGzipFile((string) $result->path);
        $this->assertIsString($xml);
        $this->assertSame(10, substr_count($xml, '<segment '));
        $this->assertSame(2, substr_count($xml, '<file '));
    }

    public function test_failed_nzb_creation_leaves_completion_untouched(): void
    {
        $this->insertRelease(1, 'n', completion: 42.5);
        $this->insertWritableCbp(200, 2000, 1, totalParts: 4, arrivedParts: 1);

        $result = $this->writeNzb(1, new RenameFailingNzbService(app(CollectionCleanupService::class)));

        $this->assertFalse($result->success);
        $this->assertSame(42.5, $this->completionFor(1));
    }

    public function test_stale_temporary_nzb_cleanup_deletes_only_old_temp_files(): void
    {
        $directory = $this->tempNzbPath.'/a';
        mkdir($directory, 0777, true);

        $oldTemporary = $directory.'/'.str_repeat('e', 36).'.nzb.gz.tmp.123.'.str_repeat('a', 12);
        $recentTemporary = $directory.'/'.str_repeat('f', 36).'.nzb.gz.tmp.456.'.str_repeat('b', 12);
        $finalNzb = $directory.'/'.str_repeat('g', 36).'.nzb.gz';

        file_put_contents($oldTemporary, 'old');
        file_put_contents($recentTemporary, 'recent');
        file_put_contents($finalNzb, 'final');
        touch($oldTemporary, time() - 7200);
        touch($recentTemporary, time() - 60);
        touch($finalNzb, time() - 7200);

        $nzb = new NzbService(app(CollectionCleanupService::class));

        $this->assertSame([$oldTemporary], $nzb->findStaleTemporaryNzbPaths(3600));
        $this->assertSame(1, $nzb->cleanupStaleTemporaryNzbs(3600));
        $this->assertFileDoesNotExist($oldTemporary);
        $this->assertFileExists($recentTemporary);
        $this->assertFileExists($finalNzb);
    }

    private function writeNzb(int $releaseId, ?NzbService $nzb = null): NzbCreationResult
    {
        $release = Release::query()->findOrFail($releaseId);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);

        return ($nzb ?? new NzbService(app(CollectionCleanupService::class)))->createNzbForRelease($release);
    }

    private function releaseProcessingService(NzbCreationResult $result): ReleaseProcessingService
    {
        return (new ReleaseProcessingService(
            nzb: new FakeNzbCreationService($result),
            releaseManagement: new DatabaseOnlyReleaseManagementService,
            collectionCleanupService: app(CollectionCleanupService::class),
        ))->setEchoCLI(false);
    }

    private function insertRelease(
        int $id,
        string $leftguid,
        int $attempts = 0,
        ?\DateTimeInterface $claimedAt = null,
        string $postdate = '2026-07-13 00:00:00',
        float $completion = 0.0,
    ): void {
        DB::table('releases')->insert([
            'id' => $id,
            'guid' => str_pad($leftguid, 36, $leftguid),
            'leftguid' => $leftguid,
            'name' => 'Release.'.$id,
            'searchname' => 'Release.'.$id,
            'groups_id' => 1,
            'categories_id' => 1,
            'postdate' => $postdate,
            'nzbstatus' => NzbService::NZB_NONE,
            'completion' => $completion,
            'nzb_creation_claimed_at' => $claimedAt?->format('Y-m-d H:i:s'),
            'nzb_creation_claim_token' => $claimedAt === null ? null : 'claimed',
        ]);

        if ($attempts > 0) {
            DB::table('release_nzb_creation_failures')->insert([
                'releases_id' => $id,
                'attempts' => $attempts,
                'last_error' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function insertCbp(int $collectionId, int $binaryId, int $releaseId): void
    {
        DB::table('collections')->insert([
            'id' => $collectionId,
            'releases_id' => $releaseId,
        ]);
        DB::table('binaries')->insert([
            'id' => $binaryId,
            'collections_id' => $collectionId,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => $binaryId,
        ]);
    }

    private function insertWritableCbp(
        int $collectionId,
        int $binaryId,
        int $releaseId,
        int $totalParts = 1,
        int $arrivedParts = 1,
    ): void {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test']);
        DB::table('collections')->insert([
            'id' => $collectionId,
            'releases_id' => $releaseId,
            'fromname' => 'poster@example.test',
            'date' => '2026-07-13 10:00:00',
            'xref' => 'alt.test:12345',
            'groups_id' => 1,
        ]);
        $this->insertWritableBinary($collectionId, $binaryId, 'Example.Release.part01.rar yEnc', $totalParts, $arrivedParts);
    }

    /**
     * Insert one binary whose subject declares $totalParts parts but for which only $arrivedParts headers arrived.
     */
    private function insertWritableBinary(
        int $collectionId,
        int $binaryId,
        string $name,
        int $totalParts,
        int $arrivedParts,
    ): void {
        DB::table('binaries')->insert([
            'id' => $binaryId,
            'collections_id' => $collectionId,
            'name' => $name,
            'totalparts' => $totalParts,
        ]);

        for ($partNumber = 1; $partNumber <= $arrivedParts; $partNumber++) {
            DB::table('parts')->insert([
                'binaries_id' => $binaryId,
                'messageid' => sprintf('<binary%d-part%d@example.test>', $binaryId, $partNumber),
                'partnumber' => $partNumber,
                'number' => $partNumber,
                'size' => 100,
            ]);
        }
    }

    private function completionFor(int $releaseId): float
    {
        return (float) DB::table('releases')->where('id', $releaseId)->value('completion');
    }

    /**
     * The real selection the completion sweep runs, not a copy of it.
     *
     * @return list<int>
     */
    private function releasesSelectedByCompletionCleanup(float $completionPercent): array
    {
        return IncompleteReleaseSweepQuery::builder($completionPercent)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function seedSettings(): void
    {
        foreach ([
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'releaseprocessingtimeout' => '120',
            'maxnzbsprocessed' => '1000',
            'nzbsplitlevel' => '1',
        ] as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
    }

    private function createSchema(): void
    {
        foreach (['parts', 'binaries', 'collections', 'release_nzb_creation_failures', 'releases', 'categories', 'root_categories', 'usenet_groups', 'settings'] as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table}");
        }

        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE root_categories (id INTEGER PRIMARY KEY, title VARCHAR(255), status INTEGER DEFAULT 1)');
        DB::statement('CREATE TABLE categories (id INTEGER PRIMARY KEY, title VARCHAR(255), root_categories_id INTEGER NULL)');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(64),
            leftguid VARCHAR(1),
            name VARCHAR(255),
            searchname VARCHAR(255),
            groups_id INTEGER,
            categories_id INTEGER,
            postdate DATETIME NULL,
            nzbstatus INTEGER,
            completion DOUBLE NOT NULL DEFAULT 0,
            repair_attempted_at DATETIME NULL,
            repair_outcome VARCHAR(16) NULL,
            nzb_creation_claimed_at DATETIME NULL,
            nzb_creation_claim_token VARCHAR(64) NULL
        )');
        DB::statement('CREATE TABLE release_nzb_creation_failures (
            releases_id INTEGER PRIMARY KEY,
            attempts INTEGER DEFAULT 0,
            last_error TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            FOREIGN KEY (releases_id) REFERENCES releases(id) ON DELETE CASCADE
        )');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255))');
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            releases_id INTEGER NULL,
            fromname VARCHAR(255) NULL,
            date DATETIME NULL,
            xref TEXT NULL,
            groups_id INTEGER NULL,
            declaredfiles INTEGER NOT NULL DEFAULT 0
        )');
        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            collections_id INTEGER,
            name VARCHAR(255) NULL,
            totalparts INTEGER NULL
        )');
        DB::statement('CREATE TABLE parts (
            binaries_id INTEGER,
            messageid VARCHAR(255) NULL,
            partnumber INTEGER NULL,
            number INTEGER NULL,
            size INTEGER NULL
        )');
        DB::table('root_categories')->insert(['id' => 1, 'title' => 'Other']);
        DB::table('categories')->insert(['id' => 1, 'title' => 'Misc', 'root_categories_id' => 1]);
    }

    private function deleteDirectory(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}

class FakeNzbCreationService extends NzbService
{
    public function __construct(private readonly NzbCreationResult $result)
    {
        parent::__construct(app(CollectionCleanupService::class));
    }

    public function createNzbForRelease(Release $release): NzbCreationResult
    {
        return $this->result;
    }
}

class RelationDroppingNzbService extends FakeNzbCreationService
{
    public function createNzbForRelease(Release $release): NzbCreationResult
    {
        $release->unsetRelation('nzbCreationFailure');

        return parent::createNzbForRelease($release);
    }
}

class LeaseObservingNzbService extends NzbService
{
    /** @var list<string> */
    public array $observedClaimTimes = [];

    public function __construct()
    {
        parent::__construct(app(CollectionCleanupService::class));
    }

    public function createNzbForRelease(Release $release): NzbCreationResult
    {
        $this->observedClaimTimes[] = (string) DB::table('releases')
            ->where('id', $release->id)
            ->value('nzb_creation_claimed_at');

        if (count($this->observedClaimTimes) === 1) {
            Carbon::setTestNow(Carbon::now()->addMinutes(2));
        }

        return NzbCreationResult::success('/unused', []);
    }
}

class WinningDuringFailureNzbService extends NzbService
{
    public string $winnerPath = '';

    public function __construct()
    {
        parent::__construct(app(CollectionCleanupService::class));
    }

    public function createNzbForRelease(Release $release): NzbCreationResult
    {
        DB::table('releases')->where('id', $release->id)->update([
            'nzbstatus' => self::NZB_ADDED,
            'nzb_creation_claimed_at' => null,
            'nzb_creation_claim_token' => null,
        ]);
        $this->winnerPath = $this->getNzbPath((string) $release->guid, 0, true);
        file_put_contents($this->winnerPath, gzencode('winner-final'));

        return NzbCreationResult::deterministic('The old worker saw empty CBP.');
    }
}

class ReclaimedDuringTransientFailureNzbService extends NzbService
{
    public function __construct()
    {
        parent::__construct(app(CollectionCleanupService::class));
    }

    public function createNzbForRelease(Release $release): NzbCreationResult
    {
        DB::table('releases')->where('id', $release->id)->update([
            'nzb_creation_claim_token' => 'worker-two',
            'nzb_creation_claimed_at' => now(),
        ]);

        return NzbCreationResult::transient('The old worker lost its claim during I/O.');
    }
}

class DatabaseOnlyReleaseManagementService extends ReleaseManagementService
{
    /**
     * @param  array<string, mixed>  $identifiers
     */
    public function deleteSingleWithService(array $identifiers, NzbService $nzb, ReleaseImageService $releaseImage): void
    {
        Release::query()->where('guid', $identifiers['g'])->delete();
    }
}

class RenameFailingNzbService extends NzbService
{
    protected function moveTemporaryNzbIntoPlace(string $temporaryPath, string $finalPath): bool
    {
        return false;
    }
}

class RecordingNzbCreationLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public array $warnings = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->warnings[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
