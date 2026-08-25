<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReleaseRepairOutcome;
use App\Facades\Search;
use App\Models\Release;
use App\Services\AdditionalProcessing\Config\PasswordInspectionMode;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseRepair\MissingFileRescanOptions;
use App\Services\ReleaseRepair\MissingFileRescanService;
use App\Services\ReleaseRepair\RescanRunBudget;
use App\Services\ReleaseRepair\RescanWindowResolver;
use DariusIII\NetNntp\Error;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * The header re-scan end to end: work out which files are missing entirely, read the group's
 * overview around where the collection was, and write the ones that belong into the stored NZB.
 */
class MissingFileRescanServiceTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private string $nzbRoot = '';

    private FakeHeaderNntp $nntp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        $this->nzbRoot = $this->makeTempDirectory('nntmux-rescan-nzbs');
        config(['nntmux_settings.path_to_nzbs' => $this->nzbRoot]);

        $this->nntp = new FakeHeaderNntp;
        $this->createSchema();
        Search::spy();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '0', 'nzbsplitlevel' => '1'];
    }

    #[Test]
    public function it_recovers_a_whole_missing_file_and_records_it_as_repaired(): void
    {
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        $this->groupCarries($this->articlesForFile(3, segments: 2, startingAt: 1150));

        $result = $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        $this->assertSame(ReleaseRepairOutcome::Repaired, $result->outcome);
        $this->assertSame(1, $result->filesRecovered);
        $this->assertSame(2, $result->segmentsAdded);
        $this->assertTrue($result->nzbRewritten);
        $this->assertSame('repaired', $this->storedOutcome(1));
        $this->assertSame(95.0, (float) DB::table('releases')->where('id', 1)->value('rescan_target_completion'));
        $this->assertSame(95.0, (float) DB::table('releases')->where('id', 1)->value('rescan_evaluated_target_completion'));

        $nzb = $this->storedNzb($release);
        $this->assertStringContainsString('[3/3] - &quot;Example.part03.rar&quot; yEnc (1/2)', $nzb);
        $this->assertStringContainsString('found-3-1@example.local', $nzb);
        $this->assertStringContainsString('found-3-2@example.local', $nzb);
    }

    #[Test]
    public function rescan_holds_a_recovery_lease_while_working_and_clears_it_afterward(): void
    {
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        $this->groupCarries($this->articlesForFile(3, segments: 2, startingAt: 1150));

        $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        $this->assertTrue($this->nntp->leaseObservedDuringFetch);
        $this->assertNull(DB::table('releases')->where('id', 1)->value('recovery_claimed_at'));
    }

    #[Test]
    public function rescan_clears_its_recovery_lease_when_work_throws(): void
    {
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        $this->nntp->throwDuringFetch = true;

        try {
            $this->service()->rescan($release, $this->rescanOptions(), $this->budget());
            $this->fail('The fake provider should have interrupted rescan.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('overview failed', $exception->getMessage());
        }

        $this->assertTrue($this->nntp->leaseObservedDuringFetch);
        $this->assertNull(DB::table('releases')->where('id', 1)->value('recovery_claimed_at'));
    }

    #[Test]
    public function a_successful_rescan_requeues_every_missed_evidence_consumer(): void
    {
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        DB::table('releases')->where('id', $release->id)->update([
            'haspreview' => 0,
            'passwordstatus' => 0,
            'nfostatus' => 0,
            'proc_nfo' => 1,
            'proc_files' => 1,
            'proc_srr' => 1,
            'proc_crc32' => 1,
            'proc_uid' => 1,
            'proc_hash16k' => 1,
            'proc_par2' => 1,
            'proc_srrdb' => 1,
            'proc_xxx' => 1,
            'proc_media_movie' => 1,
        ]);
        $this->groupCarries($this->articlesForFile(3, segments: 2, startingAt: 1150));

        $result = $this->service()->rescan($release, $this->rescanOptions(), $this->budget());
        $stored = DB::table('releases')->where('id', $release->id)->first();

        $this->assertSame(ReleaseRepairOutcome::Repaired, $result->outcome);
        $this->assertSame(3, (int) $stored->totalpart);
        $this->assertSame(100.0, (float) $stored->completion);
        $this->assertSame(-1, (int) $stored->haspreview);
        $this->assertSame(PasswordInspectionMode::pendingReleaseStatus(), (int) $stored->passwordstatus);
        $this->assertSame(-1, (int) $stored->nfostatus);

        foreach ($this->nameSourceColumns() as $column) {
            $this->assertSame(0, (int) $stored->{$column}, $column.' should be eligible once more.');
        }

        Search::shouldHaveReceived('updateRelease')->once()->with(1);
    }

    #[Test]
    public function a_rescan_without_another_file_addition_does_not_repeat_the_evidence_transition(): void
    {
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        $this->groupCarries($this->articlesForFile(3, segments: 2, startingAt: 1150));

        $this->service()->rescan($release, $this->rescanOptions(), $this->budget());
        DB::table('releases')->where('id', $release->id)->update([
            'haspreview' => 0,
            'passwordstatus' => 0,
            'nfostatus' => 0,
            'proc_files' => 1,
        ]);

        $this->service()->rescan($release->fresh(), $this->rescanOptions(), $this->budget());
        $stored = DB::table('releases')->where('id', $release->id)->first();

        $this->assertSame(0, (int) $stored->haspreview);
        $this->assertSame(0, (int) $stored->passwordstatus);
        $this->assertSame(0, (int) $stored->nfostatus);
        $this->assertSame(1, (int) $stored->proc_files);
        Search::shouldHaveReceived('updateRelease')->once()->with(1);
    }

    #[Test]
    public function a_partially_found_file_is_written_with_what_was_found(): void
    {
        // The repair engine's next pass synthesizes the rest from these message-IDs, which is why
        // a partial recovery is worth writing at all.
        // Keyed by article number, not segment number: segments 2 and 3 sit at 1151 and 1152.
        $lines = $this->articlesForFile(3, segments: 4, startingAt: 1150);
        unset($lines[1151], $lines[1152]);

        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        $this->groupCarries($lines);

        $result = $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        $this->assertSame(ReleaseRepairOutcome::RetryPending, $result->outcome);
        $this->assertSame(1, $result->filesRecovered);
        $this->assertSame(2, $result->segmentsAdded, 'Only the segments the window actually held.');
        $this->assertNull(DB::table('releases')->where('id', 1)->value('rescan_target_completion'));
        $this->assertStringContainsString('number="1"', $this->storedNzb($release));
        $this->assertStringContainsString('number="4"', $this->storedNzb($release));
    }

    #[Test]
    public function a_below_target_recovery_on_the_owed_retry_is_final_but_keeps_the_recovered_file(): void
    {
        $lines = $this->articlesForFile(3, segments: 4, startingAt: 1150);
        unset($lines[1151], $lines[1152]);

        $release = $this->releaseHolding(
            [1, 2],
            declaredFiles: 3,
            firstArticle: 1000,
            lastArticle: 1200,
            rescanOutcome: ReleaseRepairOutcome::RetryPending,
            rescanAttemptedAt: Carbon::now()->subHours(80)->toDateTimeString(),
        );
        $this->groupCarries($lines);

        $result = $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        $this->assertSame(ReleaseRepairOutcome::Failed, $result->outcome);
        $this->assertSame(2, $result->segmentsAdded);
        $this->assertStringContainsString('found-3-1@example.local', $this->storedNzb($release));
    }

    #[Test]
    public function a_reopened_rescan_that_cannot_reach_the_higher_target_stays_repaired(): void
    {
        $release = $this->releaseHolding(
            [1, 2],
            declaredFiles: 3,
            firstArticle: 1000,
            lastArticle: 1200,
            rescanOutcome: ReleaseRepairOutcome::Repaired,
            rescanTargetCompletion: 95.0,
        );
        $this->groupCarries([]);

        $result = $this->service()->rescan($release, $this->rescanOptions(targetCompletion: 99.0), $this->budget());

        $this->assertSame(ReleaseRepairOutcome::Repaired, $result->outcome);
        $stored = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(95.0, (float) $stored->rescan_target_completion);
        $this->assertSame(99.0, (float) $stored->rescan_evaluated_target_completion);
        $this->assertFalse($result->outcome->isFinal(), 'Raising policy must never make grandfathered content deletable.');
    }

    #[Test]
    public function a_rescan_reaching_target_settles_a_dangling_repair_retry(): void
    {
        $release = $this->releaseHolding(
            [1, 2],
            declaredFiles: 3,
            firstArticle: 1000,
            lastArticle: 1200,
            repairOutcome: ReleaseRepairOutcome::RetryPending,
        );
        $this->groupCarries($this->articlesForFile(3, segments: 2, startingAt: 1150));

        $this->service()->rescan($release, $this->rescanOptions(), $this->budget());
        $stored = DB::table('releases')->where('id', 1)->first();

        $this->assertSame('repaired', $stored->repair_outcome);
        $this->assertSame(95.0, (float) $stored->repair_target_completion);
        $this->assertSame(95.0, (float) $stored->repair_evaluated_target_completion);
    }

    #[Test]
    public function recovering_files_raises_completion(): void
    {
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        $this->groupCarries($this->articlesForFile(3, segments: 2, startingAt: 1150));

        $result = $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        // 2 of 3 files held, every segment present: 66.67%. With the third recovered whole: 100%.
        $this->assertEqualsWithDelta(66.67, $result->completionBefore, 0.01);
        $this->assertSame(100.0, $result->completionAfter);
        $this->assertSame(100.0, (float) DB::table('releases')->where('id', 1)->value('completion'));
    }

    #[Test]
    public function a_window_holding_nothing_of_ours_leaves_the_nzb_byte_for_byte_untouched(): void
    {
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        $before = $this->storedNzb($release);
        $this->groupCarries([]);

        $result = $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        $this->assertSame(ReleaseRepairOutcome::RetryPending, $result->outcome);
        $this->assertSame(0, $result->segmentsAdded);
        $this->assertFalse($result->nzbRewritten);
        $this->assertSame($before, $this->storedNzb($release));
        $this->assertSame('retry-pending', $this->storedOutcome(1));
    }

    #[Test]
    public function the_second_fruitless_pass_is_final(): void
    {
        $release = $this->releaseHolding(
            [1, 2],
            declaredFiles: 3,
            firstArticle: 1000,
            lastArticle: 1200,
            rescanOutcome: ReleaseRepairOutcome::RetryPending,
            rescanAttemptedAt: Carbon::now()->subHours(80)->toDateTimeString(),
        );
        $this->groupCarries([]);

        $result = $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        $this->assertSame(ReleaseRepairOutcome::Failed, $result->outcome);
        $this->assertTrue($result->outcome->isFinal(), 'Only now may the sweep touch it.');
    }

    #[Test]
    public function another_posters_headers_in_the_window_are_never_attached(): void
    {
        // A wrong attachment writes a message-ID into an NZB that fails at download time.
        $lines = $this->articlesForFile(3, segments: 2, startingAt: 1150, poster: 'someone.else@example.org');

        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        $before = $this->storedNzb($release);
        $this->groupCarries($lines);

        $result = $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        $this->assertSame(0, $result->segmentsAdded);
        $this->assertSame($before, $this->storedNzb($release));
    }

    #[Test]
    public function a_similarly_named_post_declaring_a_different_file_count_is_never_attached(): void
    {
        $lines = $this->articlesForFile(3, segments: 2, startingAt: 1150, declaredFiles: 9);

        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        $before = $this->storedNzb($release);
        $this->groupCarries($lines);

        $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        $this->assertSame($before, $this->storedNzb($release));
    }

    #[Test]
    public function a_different_release_with_the_same_shape_is_never_attached(): void
    {
        $lines = $this->articlesForFile(3, segments: 2, startingAt: 1150, name: 'Something.Else');

        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        $before = $this->storedNzb($release);
        $this->groupCarries($lines);

        $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        $this->assertSame($before, $this->storedNzb($release));
    }

    #[Test]
    public function a_file_the_nzb_already_holds_is_never_appended_twice(): void
    {
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        // `+` rather than array_merge: the keys are article numbers, which merge would renumber.
        $this->groupCarries(
            $this->articlesForFile(1, segments: 2, startingAt: 1000)
            + $this->articlesForFile(3, segments: 2, startingAt: 1150)
        );

        $result = $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        $this->assertSame(1, $result->filesRecovered);
        $this->assertSame(3, substr_count($this->storedNzb($release), '<file '));
    }

    #[Test]
    public function a_window_wider_than_the_per_release_ceiling_spends_nothing(): void
    {
        $this->nntp->groupLast = 900000;
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 900000);
        $this->groupCarries($this->articlesForFile(3, segments: 2, startingAt: 1150));

        $result = $this->service()->rescan($release, $this->rescanOptions(maxArticlesPerRelease: 5000), $this->budget());

        $this->assertSame(ReleaseRepairOutcome::SkippedBudget, $result->outcome);
        $this->assertSame(0, $result->overviewLinesFetched);
        $this->assertSame(0, $this->nntp->xoverCalls, 'Not one overview command may be sent.');
        $this->assertSame('skipped-budget', $this->storedOutcome(1));
        $this->assertTrue($result->outcome->isFinal());
    }

    #[Test]
    public function a_run_stops_fetching_once_its_budget_is_spent(): void
    {
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        // Other people's posts in the first batch, and ours in the last one.
        $this->groupCarries(
            $this->articlesForFile(3, segments: 6, startingAt: 1000, poster: 'someone.else@example.org')
            + $this->articlesForFile(3, segments: 2, startingAt: 1195)
        );

        $budget = new RescanRunBudget(1);
        $result = $this->service()->rescan($release, $this->rescanOptions(overviewBatchSize: 100), $budget);

        $this->assertSame(1, $this->nntp->xoverCalls, 'Fetching stops as soon as the budget is gone.');
        $this->assertSame(6, $budget->spent());
        $this->assertTrue($budget->isExhausted());
        $this->assertSame(6, $result->overviewLinesFetched);
        $this->assertSame(0, $result->segmentsAdded, 'The recoverable file sat past the first batch.');
        $this->assertNull($result->outcome, 'An unread tail cannot support a negative verdict.');
        $this->assertNull($this->storedOutcome(1));

        $retry = $this->service()->rescan($release->fresh(), $this->rescanOptions(overviewBatchSize: 100), $this->budget());

        $this->assertSame(ReleaseRepairOutcome::Repaired, $retry->outcome);
        $this->assertGreaterThan(1, $this->nntp->xoverCalls, 'The next pass starts the window again with a fresh budget.');
    }

    #[Test]
    public function an_incomplete_window_persists_matches_without_writing_a_shortfall_verdict(): void
    {
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        $lines = $this->articlesForFile(3, segments: 4, startingAt: 1000);
        unset($lines[1001], $lines[1002]);
        $this->groupCarries($lines);

        $result = $this->service()->rescan(
            $release,
            $this->rescanOptions(overviewBatchSize: 100),
            new RescanRunBudget(1),
        );

        $this->assertNull($result->outcome);
        $this->assertNull($this->storedOutcome(1));
        $this->assertSame(2, $result->segmentsAdded);
        $this->assertStringContainsString('found-3-1@example.local', $this->storedNzb($release));
    }

    #[Test]
    public function an_incomplete_window_may_record_repaired_when_its_matches_reach_the_target(): void
    {
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        $this->groupCarries($this->articlesForFile(3, segments: 2, startingAt: 1000));

        $result = $this->service()->rescan(
            $release,
            $this->rescanOptions(overviewBatchSize: 100),
            new RescanRunBudget(1),
        );

        $this->assertSame(ReleaseRepairOutcome::Repaired, $result->outcome);
        $this->assertSame('repaired', $this->storedOutcome(1));
        $this->assertSame(95.0, (float) DB::table('releases')->where('id', 1)->value('rescan_target_completion'));
    }

    #[Test]
    public function a_release_already_past_its_run_budget_keeps_its_state(): void
    {
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        $this->groupCarries($this->articlesForFile(3, segments: 2, startingAt: 1150));

        $budget = new RescanRunBudget(0);
        $result = $this->service()->rescan($release, $this->rescanOptions(), $budget);

        $this->assertNull($result->outcome, 'It never had its turn, so it cannot have failed.');
        $this->assertNull($this->storedOutcome(1));
        $this->assertSame(0, $this->nntp->xoverCalls);
    }

    #[Test]
    public function a_release_declaring_no_more_than_it_holds_is_stamped_without_a_window(): void
    {
        $release = $this->releaseHolding([1, 2, 3], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);

        $result = $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        $this->assertSame(ReleaseRepairOutcome::SkippedFloor, $result->outcome);
        $this->assertSame(0, $this->nntp->xoverCalls);
        $this->assertSame('skipped-floor', $this->storedOutcome(1));
    }

    #[Test]
    public function a_dry_run_resolves_the_window_but_fetches_and_writes_nothing(): void
    {
        $release = $this->releaseHolding([1, 2], declaredFiles: null, firstArticle: 1000, lastArticle: 1200);
        $before = $this->storedNzb($release);
        $this->groupCarries($this->articlesForFile(3, segments: 2, startingAt: 1150));

        $result = $this->service()->rescan($release, $this->rescanOptions(dryRun: true), $this->budget());

        $this->assertNull($result->outcome);
        $this->assertSame(0, $this->nntp->xoverCalls);
        $this->assertSame($before, $this->storedNzb($release));
        $this->assertNull($this->storedOutcome(1));
        $this->assertNull(
            DB::table('releases')->where('id', 1)->value('declaredfiles'),
            'A dry run must not persist the derived count either.'
        );
        $this->assertStringContainsString('Would read', $result->reason);
    }

    #[Test]
    public function a_legacy_release_derives_its_declared_count_from_the_stored_nzb(): void
    {
        $release = $this->releaseHolding([1, 2], declaredFiles: null, firstArticle: 1000, lastArticle: 1200);
        $this->groupCarries($this->articlesForFile(3, segments: 2, startingAt: 1150));

        $result = $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        $this->assertSame(3, $result->declaredFiles);
        $this->assertSame(3, (int) DB::table('releases')->where('id', 1)->value('declaredfiles'));
        $this->assertSame(ReleaseRepairOutcome::Repaired, $result->outcome);
    }

    #[Test]
    public function a_legacy_window_is_bisected_from_the_release_postdate(): void
    {
        // No anchors: the group is bisected for the article numbers either side of the postdate,
        // using the same date-to-article search backfill uses.
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: null, lastArticle: null);
        $this->nntp->articleDates = [
            1 => strtotime('2024-01-01 00:00:00'),
            2000 => strtotime('2024-01-03 00:00:00'),
        ];
        $this->groupCarries($this->articlesForFile(3, segments: 2, startingAt: 1000));

        $result = $this->service()->rescan($release, $this->rescanOptions(windowMinutes: 60), $this->budget());

        $this->assertGreaterThan(0, $this->nntp->xoverCalls);
        $this->assertGreaterThan(0, $result->articlesRequested);
        $this->assertLessThan(2000, $result->articlesRequested, 'A bisected window is a slice, not the group.');
        $this->assertSame(ReleaseRepairOutcome::Repaired, $result->outcome);
    }

    #[Test]
    public function a_missing_nzb_leaves_the_rescan_state_untouched(): void
    {
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        unlink((string) app(NzbService::class)->nzbPath((string) $release->guid));

        $result = $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        $this->assertNull($result->outcome);
        $this->assertNull($this->storedOutcome(1));
        $this->assertNull(DB::table('releases')->where('id', 1)->value('rescan_attempted_at'));
        $this->assertNull(DB::table('releases')->where('id', 1)->value('recovery_claimed_at'));
    }

    #[Test]
    public function a_group_the_provider_will_not_select_runs_the_normal_two_passes(): void
    {
        // A dropped group and a sulking connection look identical from here, so this takes the
        // same two passes as everything else rather than being guessed at. Leaving it unstamped
        // would make the release undeletable for good, since the sweep waits on this column.
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        $this->nntp->selectFails = true;

        $result = $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        $this->assertSame(ReleaseRepairOutcome::RetryPending, $result->outcome);
        $this->assertSame('retry-pending', $this->storedOutcome(1));
    }

    #[Test]
    public function a_group_that_stays_unselectable_settles_at_failed(): void
    {
        $release = $this->releaseHolding(
            [1, 2],
            declaredFiles: 3,
            firstArticle: 1000,
            lastArticle: 1200,
            rescanOutcome: ReleaseRepairOutcome::RetryPending,
            rescanAttemptedAt: Carbon::now()->subHours(80)->toDateTimeString(),
        );
        $this->nntp->selectFails = true;

        $result = $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        $this->assertSame(ReleaseRepairOutcome::Failed, $result->outcome);
        $this->assertTrue($result->outcome->isFinal(), 'The reaper must not be blocked forever.');
    }

    #[Test]
    public function a_release_pointing_at_a_group_that_no_longer_exists_is_stamped_final(): void
    {
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200);
        DB::table('releases')->where('id', 1)->update(['groups_id' => 999]);

        $result = $this->service()->rescan($release->fresh(), $this->rescanOptions(), $this->budget());

        $this->assertSame(ReleaseRepairOutcome::SkippedFloor, $result->outcome);
        $this->assertTrue($result->outcome->isFinal(), 'Nothing will put the group row back.');
        $this->assertSame(0, $this->nntp->xoverCalls);
    }

    #[Test]
    public function a_release_with_no_anchors_and_no_usable_postdate_is_stamped_final(): void
    {
        // Neither will ever arrive, so there is nothing to aim a window at on any later pass.
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: null, lastArticle: null);
        DB::table('releases')->where('id', 1)->update(['postdate' => null]);

        $result = $this->service()->rescan($release->fresh(), $this->rescanOptions(), $this->budget());

        $this->assertSame(ReleaseRepairOutcome::SkippedFloor, $result->outcome);
        $this->assertTrue($result->outcome->isFinal());
    }

    #[Test]
    public function held_files_with_no_file_index_stop_the_release_being_re_scanned(): void
    {
        // Without an index on every held file there is no way to know which indices are already
        // ours, and appending a duplicate is worse than doing nothing. Final, not silent: the
        // sweep is waiting on this column.
        $release = $this->releaseHolding([1, 2], declaredFiles: 3, firstArticle: 1000, lastArticle: 1200, withFileIndex: false);

        $result = $this->service()->rescan($release, $this->rescanOptions(), $this->budget());

        $this->assertSame(ReleaseRepairOutcome::SkippedFloor, $result->outcome);
        $this->assertTrue($result->outcome->isFinal());
        $this->assertSame(0, $this->nntp->xoverCalls);
    }

    private function service(): MissingFileRescanService
    {
        // Only `echoCli` reaches the date bisection; the rest of BinariesConfig is irrelevant here.
        $binaries = new BinariesService(config: new BinariesConfig(echoCli: false));
        $binaries->setNntp($this->nntp);

        return new MissingFileRescanService(
            app(NzbService::class),
            $this->nntp,
            new RescanWindowResolver($binaries),
        );
    }

    private function rescanOptions(
        float $targetCompletion = 95.0,
        int $maxArticlesPerRelease = 500000,
        int $windowMinutes = 0,
        int $overviewBatchSize = 20000,
        bool $dryRun = false,
    ): MissingFileRescanOptions {
        return new MissingFileRescanOptions(
            targetCompletion: $targetCompletion,
            windowMinutes: $windowMinutes,
            maxArticlesPerRelease: $maxArticlesPerRelease,
            overviewBatchSize: $overviewBatchSize,
            dryRun: $dryRun,
        );
    }

    private function budget(): RescanRunBudget
    {
        return new RescanRunBudget(5000000);
    }

    private function groupCarries(array $lines): void
    {
        $this->nntp->articles = $lines;
    }

    /**
     * Overview lines for one file of a post, as the server would return them.
     *
     * @return array<int, array<string, mixed>> Keyed by article number.
     */
    private function articlesForFile(
        int $fileIndex,
        int $segments,
        int $startingAt,
        int $declaredFiles = 3,
        string $poster = 'poster@example.org',
        string $name = 'Example',
    ): array {
        $lines = [];

        for ($segment = 1; $segment <= $segments; $segment++) {
            $number = $startingAt + $segment - 1;
            $lines[$number] = [
                'Number' => (string) $number,
                'Subject' => sprintf(
                    '[%d/%d] - "%s.part%02d.rar" yEnc (%d/%d)',
                    $fileIndex,
                    $declaredFiles,
                    $name,
                    $fileIndex,
                    $segment,
                    $segments,
                ),
                'From' => $poster,
                'Date' => '02 Jan 2024 00:00:00 GMT',
                'Message-ID' => sprintf('<found-%d-%d@example.local>', $fileIndex, $segment),
                'Bytes' => '768000',
            ];
        }

        return $lines;
    }

    /**
     * @param  list<int>  $fileIndices  Which of the declared files the NZB holds.
     */
    private function releaseHolding(
        array $fileIndices,
        ?int $declaredFiles,
        ?int $firstArticle,
        ?int $lastArticle,
        ?ReleaseRepairOutcome $rescanOutcome = null,
        ?string $rescanAttemptedAt = null,
        bool $withFileIndex = true,
        ?ReleaseRepairOutcome $repairOutcome = null,
        ?float $repairTargetCompletion = null,
        ?float $rescanTargetCompletion = null,
    ): Release {
        $guid = sprintf('%032x', 1);

        DB::table('usenet_groups')->insert(['id' => 7, 'name' => 'alt.binaries.test']);

        DB::table('releases')->insert([
            'id' => 1,
            'guid' => $guid,
            'groups_id' => 7,
            'nzbstatus' => 1,
            'completion' => 66.67,
            'totalpart' => \count($fileIndices),
            'declaredfiles' => $declaredFiles,
            'firstarticle' => $firstArticle,
            'lastarticle' => $lastArticle,
            'repair_outcome' => $repairOutcome?->value,
            'repair_target_completion' => $repairTargetCompletion,
            'repair_evaluated_target_completion' => $repairTargetCompletion,
            'rescan_outcome' => $rescanOutcome?->value,
            'rescan_attempted_at' => $rescanAttemptedAt,
            'rescan_target_completion' => $rescanTargetCompletion,
            'rescan_evaluated_target_completion' => $rescanTargetCompletion,
            'postdate' => '2024-01-02 00:00:00',
        ]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'."\n";

        foreach ($fileIndices as $index) {
            $subject = $withFileIndex
                ? sprintf('[%d/3] - "Example.part%02d.rar" yEnc (1/2)', $index, $index)
                : sprintf('"Example.part%02d.rar" yEnc (1/2)', $index);
            $xml .= '  <file poster="poster@example.org" date="1704153600" subject="'.htmlspecialchars($subject, ENT_XML1 | ENT_COMPAT).'">'."\n"
                .'    <groups><group>alt.binaries.test</group></groups>'."\n    <segments>\n"
                .'      <segment bytes="768000" number="1">held-'.$index.'-1@example.local</segment>'."\n"
                .'      <segment bytes="768000" number="2">held-'.$index.'-2@example.local</segment>'."\n"
                ."    </segments>\n  </file>\n";
        }

        $xml .= '</nzb>'."\n";

        $path = app(NzbService::class)->getNzbPath($guid, 0, true);
        file_put_contents($path, gzencode($xml));

        return Release::query()->findOrFail(1);
    }

    private function storedNzb(Release $release): string
    {
        return (string) app(NzbService::class)->readNzbContents((string) $release->guid);
    }

    private function storedOutcome(int $id): ?string
    {
        $value = DB::table('releases')->where('id', $id)->value('rescan_outcome');

        return $value === null ? null : (string) $value;
    }

    /**
     * @return list<string>
     */
    private function nameSourceColumns(): array
    {
        return [
            'proc_nfo',
            'proc_files',
            'proc_srr',
            'proc_crc32',
            'proc_uid',
            'proc_hash16k',
            'proc_par2',
            'proc_srrdb',
            'proc_xxx',
            'proc_media_movie',
        ];
    }

    private function createSchema(): void
    {
        DB::statement('DROP TABLE IF EXISTS releases');
        DB::statement('DROP TABLE IF EXISTS usenet_groups');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(64),
            groups_id INTEGER NULL,
            nzbstatus INTEGER NOT NULL DEFAULT 0,
            completion DOUBLE NOT NULL DEFAULT 0,
            totalpart INTEGER NOT NULL DEFAULT 0,
            declaredfiles INTEGER NULL,
            firstarticle INTEGER NULL,
            lastarticle INTEGER NULL,
            repair_attempted_at DATETIME NULL,
            repair_outcome VARCHAR(16) NULL,
            repair_target_completion DOUBLE NULL,
            repair_evaluated_target_completion DOUBLE NULL,
            rescan_attempted_at DATETIME NULL,
            rescan_outcome VARCHAR(16) NULL,
            rescan_target_completion DOUBLE NULL,
            rescan_evaluated_target_completion DOUBLE NULL,
            recovery_claimed_at DATETIME NULL,
            postdate DATETIME NULL,
            haspreview INTEGER NOT NULL DEFAULT -1,
            passwordstatus INTEGER NOT NULL DEFAULT -1,
            pp_timeout_count INTEGER NOT NULL DEFAULT 2,
            predb_id INTEGER NOT NULL DEFAULT 0,
            isrenamed INTEGER NOT NULL DEFAULT 0,
            nfostatus INTEGER NOT NULL DEFAULT -1,
            proc_nfo INTEGER NOT NULL DEFAULT 0,
            proc_files INTEGER NOT NULL DEFAULT 0,
            proc_srr INTEGER NOT NULL DEFAULT 0,
            proc_crc32 INTEGER NOT NULL DEFAULT 0,
            proc_uid INTEGER NOT NULL DEFAULT 0,
            proc_hash16k INTEGER NOT NULL DEFAULT 0,
            proc_par2 INTEGER NOT NULL DEFAULT 0,
            proc_srrdb INTEGER NOT NULL DEFAULT 0,
            proc_xxx INTEGER NOT NULL DEFAULT 0,
            proc_media_movie INTEGER NOT NULL DEFAULT 0
        )');
        DB::statement('CREATE TABLE video_data (releases_id INTEGER PRIMARY KEY, videocodec VARCHAR(255) NULL)');
        DB::statement('CREATE TABLE audio_data (id INTEGER PRIMARY KEY, releases_id INTEGER, audioformat VARCHAR(255) NULL)');
        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            first_record INTEGER NOT NULL DEFAULT 0,
            first_record_postdate DATETIME NULL,
            last_record INTEGER NOT NULL DEFAULT 0,
            last_record_postdate DATETIME NULL
        )');
        DB::statement('CREATE TABLE collections (id INTEGER PRIMARY KEY, date DATETIME NULL)');
        DB::statement('CREATE TABLE binaries (id INTEGER PRIMARY KEY, collections_id INTEGER)');
        DB::statement('CREATE TABLE parts (binaries_id INTEGER, number INTEGER)');
    }
}

/**
 * Answers XOVER ranges from canned overview lines, without touching a socket.
 *
 * Follows the repo's existing fake-NNTP pattern (see BinariesRejectedScanBatchTest): subclass the
 * real client and override only the two commands under test, so the service under test is wired
 * exactly as it is in production.
 */
final class FakeHeaderNntp extends NNTPService
{
    /** @var array<int, array<string, mixed>> Article number => overview line. */
    public array $articles = [];

    /** @var array<int, int> Article number => unix time, for the legacy date bisection. */
    public array $articleDates = [];

    public int $xoverCalls = 0;

    public bool $leaseObservedDuringFetch = false;

    public bool $throwDuringFetch = false;

    public bool $selectFails = false;

    public int $groupFirst = 1;

    public int $groupLast = 2000;

    public function __construct() {}

    public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
    {
        if ($this->selectFails) {
            return new Error('No such group');
        }

        return ['group' => $group, 'first' => $this->groupFirst, 'last' => $this->groupLast];
    }

    public function getXOVER(string $range): mixed
    {
        $this->xoverCalls++;
        $this->leaseObservedDuringFetch = DB::table('releases')
            ->where('id', 1)
            ->whereNotNull('recovery_claimed_at')
            ->exists();

        if ($this->throwDuringFetch) {
            throw new \RuntimeException('overview failed');
        }

        [$first, $last] = array_pad(explode('-', $range, 2), 2, null);
        $first = (int) $first;
        $last = $last === null || $last === '' ? $first : (int) $last;

        if ($first === $last && $this->articleDates !== []) {
            // A single-article probe: the date bisection asking when this article was posted.
            return [[
                'Number' => (string) $first,
                'Date' => gmdate('D, d M Y H:i:s \G\M\T', $this->dateFor($first)),
                'Subject' => 'probe',
                'From' => 'probe@example.org',
                'Message-ID' => '<probe@example.local>',
            ]];
        }

        $lines = [];

        foreach ($this->articles as $number => $line) {
            if ($number >= $first && $number <= $last) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public function doQuit(bool $force = false): mixed
    {
        return true;
    }

    public function __destruct() {}

    /**
     * Linear interpolation between the pinned article dates, so a bisection converges.
     */
    private function dateFor(int $article): int
    {
        $numbers = array_keys($this->articleDates);
        $low = min($numbers);
        $high = max($numbers);
        $article = max($low, min($high, $article));

        $span = $high - $low;

        if ($span <= 0) {
            return $this->articleDates[$low];
        }

        $elapsed = $this->articleDates[$high] - $this->articleDates[$low];

        return (int) round($this->articleDates[$low] + ($elapsed * (($article - $low) / $span)));
    }
}
