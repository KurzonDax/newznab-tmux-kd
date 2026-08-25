<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReleaseRepairOutcome;
use App\Services\ReleaseRepair\ReleaseRepairCandidateQuery;
use App\Services\ReleaseRepair\RescanCandidateQuery;
use App\Services\Releases\IncompleteReleaseSweepQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * The invariant the whole issue exists for: the completion sweep never deletes a release either
 * recovery pass -- segment repair, or the header re-scan -- has not finished with.
 */
class ReleaseRepairGateTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createReleasesTable();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[Test]
    public function the_sweep_deletes_only_releases_with_a_final_repair_outcome(): void
    {
        $this->insertRelease(1, completion: 40.0, outcome: ReleaseRepairOutcome::Failed);
        $this->insertRelease(2, completion: 5.0, outcome: ReleaseRepairOutcome::SkippedFloor);
        $this->insertRelease(3, completion: 40.0, outcome: ReleaseRepairOutcome::RetryPending);
        $this->insertRelease(4, completion: 40.0, outcome: ReleaseRepairOutcome::Repaired);
        $this->insertRelease(5, completion: 40.0, outcome: null);

        $this->assertSame([1, 2], $this->sweptIds(95.0));
    }

    #[Test]
    public function a_measured_but_unrepaired_release_is_never_swept(): void
    {
        // The reason the gate exists: "incomplete" usually means the header scan missed
        // articles that are still on the provider, not that the post is gone.
        $this->insertRelease(1, completion: 40.0, outcome: null);

        $this->assertSame([], $this->sweptIds(95.0));
    }

    #[Test]
    public function the_never_measured_sentinel_stays_exempt(): void
    {
        // completion = 0 means "nothing declared a part total", not "0% complete".
        $this->insertRelease(1, completion: 0.0, outcome: ReleaseRepairOutcome::Failed);

        $this->assertSame([], $this->sweptIds(95.0));
    }

    #[Test]
    public function a_measured_nzb_import_is_selected_by_both_recovery_paths_before_the_sweep(): void
    {
        $this->insertRelease(1, completion: 50.0, outcome: null, declaredFiles: 2, totalPart: 1);

        $this->assertSame([1], ReleaseRepairCandidateQuery::batch(10, 95.0, 72)->pluck('id')->map(intval(...))->all());
        $this->assertSame([1], RescanCandidateQuery::batch(10, 95.0, 72)->pluck('id')->map(intval(...))->all());
        $this->assertSame([], $this->sweptIds(95.0));
    }

    #[Test]
    public function a_release_at_or_above_the_threshold_is_never_swept(): void
    {
        $this->insertRelease(1, completion: 95.0, outcome: ReleaseRepairOutcome::Failed);
        $this->insertRelease(2, completion: 99.9, outcome: ReleaseRepairOutcome::Failed);

        $this->assertSame([], $this->sweptIds(95.0));
    }

    #[Test]
    public function candidate_selection_prefers_retries_whose_window_has_passed(): void
    {
        $this->insertRelease(1, completion: 40.0, outcome: null, postdate: '2020-01-01 00:00:00');
        $this->insertRelease(
            2,
            completion: 40.0,
            outcome: ReleaseRepairOutcome::RetryPending,
            attemptedAt: Carbon::now()->subHours(100)->toDateTimeString(),
        );

        $batch = ReleaseRepairCandidateQuery::batch(10, 95.0, 72);

        $this->assertSame([2, 1], $batch->pluck('id')->map(intval(...))->all());
    }

    #[Test]
    public function a_retry_inside_its_window_is_not_selected(): void
    {
        // The state machine owns time. A release attempted 71 hours ago is still owed its
        // window: articles may still be propagating across the provider farm.
        $this->insertRelease(
            1,
            completion: 40.0,
            outcome: ReleaseRepairOutcome::RetryPending,
            attemptedAt: Carbon::now()->subHours(71)->toDateTimeString(),
        );

        $this->assertTrue(ReleaseRepairCandidateQuery::batch(10, 95.0, 72)->isEmpty());
    }

    #[Test]
    public function releases_with_a_final_outcome_are_never_selected_again(): void
    {
        // Two network passes per release, no more.
        $this->insertRelease(1, completion: 40.0, outcome: ReleaseRepairOutcome::Failed);
        $this->insertRelease(2, completion: 5.0, outcome: ReleaseRepairOutcome::SkippedFloor);
        $this->insertRelease(3, completion: 96.0, outcome: ReleaseRepairOutcome::Repaired);

        $this->assertTrue(ReleaseRepairCandidateQuery::batch(10, 95.0, 72)->isEmpty());
    }

    #[Test]
    public function never_attempted_releases_are_taken_newest_first(): void
    {
        // Matches how additional processing claims work, so fresh releases keep priority and
        // the legacy backlog drains from the tail.
        $this->insertRelease(1, completion: 40.0, outcome: null, postdate: '2019-01-01 00:00:00');
        $this->insertRelease(2, completion: 40.0, outcome: null, postdate: '2026-01-01 00:00:00');
        $this->insertRelease(3, completion: 40.0, outcome: null, postdate: '2023-01-01 00:00:00');

        $this->assertSame([2, 3, 1], ReleaseRepairCandidateQuery::batch(10, 95.0, 72)->pluck('id')->map(intval(...))->all());
    }

    #[Test]
    public function the_batch_limit_is_honoured(): void
    {
        for ($id = 1; $id <= 10; $id++) {
            $this->insertRelease($id, completion: 40.0, outcome: null);
        }

        $this->assertCount(4, ReleaseRepairCandidateQuery::batch(4, 95.0, 72));
    }

    #[Test]
    public function only_a_given_up_on_release_is_deletable(): void
    {
        // `repaired` is an ending too, but a happy one -- the release lives.
        $this->assertSame(
            ['failed', 'skipped-floor', 'skipped-budget'],
            ReleaseRepairOutcome::deletableValues()
        );
        $this->assertFalse(ReleaseRepairOutcome::Repaired->isFinal());
        $this->assertFalse(ReleaseRepairOutcome::RetryPending->isFinal());
        $this->assertTrue(ReleaseRepairOutcome::Failed->isFinal());
        $this->assertTrue(ReleaseRepairOutcome::SkippedFloor->isFinal());
        $this->assertTrue(ReleaseRepairOutcome::SkippedBudget->isFinal());
    }

    #[Test]
    public function a_release_the_re_scan_still_owes_a_pass_to_is_not_swept(): void
    {
        // Repair has given up on it, but it declares more files than it holds and the header
        // re-scan has never looked. The two passes recover different things.
        $this->insertRelease(
            1,
            completion: 40.0,
            outcome: ReleaseRepairOutcome::Failed,
            declaredFiles: 40,
            totalPart: 38,
        );

        $this->assertSame([], $this->sweptIds(95.0));
    }

    #[Test]
    public function a_release_the_re_scan_has_given_up_on_is_swept(): void
    {
        $this->insertRelease(
            1,
            completion: 40.0,
            outcome: ReleaseRepairOutcome::Failed,
            declaredFiles: 40,
            totalPart: 38,
            rescanOutcome: ReleaseRepairOutcome::Failed,
        );
        $this->insertRelease(
            2,
            completion: 40.0,
            outcome: ReleaseRepairOutcome::Failed,
            declaredFiles: 40,
            totalPart: 38,
            rescanOutcome: ReleaseRepairOutcome::SkippedBudget,
        );
        $this->insertRelease(
            3,
            completion: 40.0,
            outcome: ReleaseRepairOutcome::Failed,
            declaredFiles: 40,
            totalPart: 38,
            rescanOutcome: ReleaseRepairOutcome::RetryPending,
        );

        $this->assertSame([1, 2], $this->sweptIds(95.0));
    }

    #[Test]
    public function a_release_with_a_derived_nothing_to_re_scan_verdict_is_swept_without_waiting_for_a_stamp(): void
    {
        // Null is unresolved and still owed a re-scan visit.
        $this->insertRelease(1, completion: 40.0, outcome: ReleaseRepairOutcome::Failed, declaredFiles: null);
        // Zero: derived, and the NZB declares no usable file count.
        $this->insertRelease(2, completion: 40.0, outcome: ReleaseRepairOutcome::Failed, declaredFiles: 0);
        // Holds everything it declared -- the shortfall is segments, which is repair's job.
        $this->insertRelease(3, completion: 40.0, outcome: ReleaseRepairOutcome::Failed, declaredFiles: 12, totalPart: 12);

        $this->assertSame([2, 3], $this->sweptIds(95.0));
    }

    #[Test]
    public function the_re_scan_takes_the_smallest_shortfall_first(): void
    {
        // A release missing two files of forty is both likeliest to be recovered and cheapest to
        // try; one missing seven hundred is a posting session that never arrived.
        $this->insertRelease(1, completion: 40.0, outcome: null, declaredFiles: 740, totalPart: 40);
        $this->insertRelease(2, completion: 40.0, outcome: null, declaredFiles: 40, totalPart: 38);
        $this->insertRelease(3, completion: 40.0, outcome: null, declaredFiles: 60, totalPart: 50);

        $this->assertSame(
            [2, 3, 1],
            RescanCandidateQuery::batch(10, 95.0, 72)->pluck('id')->map(intval(...))->all()
        );
    }

    #[Test]
    public function the_re_scan_skips_releases_that_declare_no_more_than_they_hold(): void
    {
        $this->insertRelease(1, completion: 40.0, outcome: null, declaredFiles: 0, totalPart: 12);
        $this->insertRelease(2, completion: 40.0, outcome: null, declaredFiles: 12, totalPart: 12);

        $this->assertTrue(RescanCandidateQuery::batch(10, 95.0, 72)->isEmpty());
    }

    #[Test]
    public function the_re_scan_still_visits_legacy_releases_whose_declared_count_is_unresolved(): void
    {
        // Deriving it means reading the stored NZB, so the query cannot do it -- the pass does,
        // on first visit, and persists the answer.
        $this->insertRelease(1, completion: 40.0, outcome: null, declaredFiles: null, totalPart: 12);

        $this->assertSame([1], RescanCandidateQuery::batch(10, 95.0, 72)->pluck('id')->map(intval(...))->all());
    }

    #[Test]
    public function rescan_and_deletion_admission_are_mutually_exclusive_for_an_unresolved_legacy_release(): void
    {
        $this->insertRelease(
            1,
            completion: 40.0,
            outcome: ReleaseRepairOutcome::Failed,
            declaredFiles: null,
            totalPart: 12,
        );

        $this->assertSame(
            [1],
            RescanCandidateQuery::batch(10, 95.0, 72)->pluck('id')->map(intval(...))->all()
        );
        $this->assertSame([], $this->sweptIds(95.0));
    }

    #[Test]
    public function the_sweep_excludes_live_processing_claims_but_allows_stale_ones(): void
    {
        $live = Carbon::now()->toDateTimeString();
        $stale = Carbon::now()->subHour()->toDateTimeString();

        $this->insertRelease(1, completion: 40.0, outcome: ReleaseRepairOutcome::Failed, declaredFiles: 0, additionalClaimedAt: $live);
        $this->insertRelease(2, completion: 40.0, outcome: ReleaseRepairOutcome::Failed, declaredFiles: 0, recoveryClaimedAt: $live);
        $this->insertRelease(3, completion: 40.0, outcome: ReleaseRepairOutcome::Failed, declaredFiles: 0, additionalClaimedAt: $stale);
        $this->insertRelease(4, completion: 40.0, outcome: ReleaseRepairOutcome::Failed, declaredFiles: 0, recoveryClaimedAt: $stale);
        $this->insertRelease(5, completion: 40.0, outcome: ReleaseRepairOutcome::Failed, declaredFiles: 0);

        $this->assertSame([3, 4, 5], $this->sweptIds(95.0));
    }

    #[Test]
    public function recovery_candidate_queries_skip_live_leases_but_reclaim_stale_ones(): void
    {
        $live = Carbon::now()->toDateTimeString();
        $stale = Carbon::now()->subHour()->toDateTimeString();

        $this->insertRelease(1, completion: 40.0, outcome: null, declaredFiles: 40, totalPart: 38, recoveryClaimedAt: $live);
        $this->insertRelease(2, completion: 40.0, outcome: null, declaredFiles: 40, totalPart: 38, recoveryClaimedAt: $stale);

        $this->assertSame(
            [2],
            ReleaseRepairCandidateQuery::batch(10, 95.0, 72)->pluck('id')->map(intval(...))->all(),
        );
        $this->assertSame(
            [2],
            RescanCandidateQuery::batch(10, 95.0, 72)->pluck('id')->map(intval(...))->all(),
        );
    }

    #[Test]
    public function unresolved_legacy_releases_queue_behind_the_ones_with_a_known_shortfall(): void
    {
        // `NULL - totalpart` is not a shortfall. Reading it as one would sort the whole legacy
        // backlog to the front of every batch, largest release first -- the opposite of
        // cheapest-first, and it would starve the rows we can already cost.
        $this->insertRelease(1, completion: 40.0, outcome: null, declaredFiles: null, totalPart: 900, postdate: '2019-01-01 00:00:00');
        $this->insertRelease(2, completion: 40.0, outcome: null, declaredFiles: null, totalPart: 12, postdate: '2026-01-01 00:00:00');
        $this->insertRelease(3, completion: 40.0, outcome: null, declaredFiles: 740, totalPart: 40);
        $this->insertRelease(4, completion: 40.0, outcome: null, declaredFiles: 40, totalPart: 38);

        $this->assertSame(
            [4, 3, 2, 1],
            RescanCandidateQuery::batch(10, 95.0, 72)->pluck('id')->map(intval(...))->all(),
            'Known shortfalls cheapest-first, then the unresolved backlog newest-first.'
        );
    }

    #[Test]
    public function the_re_scan_prefers_retries_whose_window_has_passed(): void
    {
        $this->insertRelease(1, completion: 40.0, outcome: null, declaredFiles: 40, totalPart: 38);
        $this->insertRelease(
            2,
            completion: 40.0,
            outcome: null,
            declaredFiles: 740,
            totalPart: 40,
            rescanOutcome: ReleaseRepairOutcome::RetryPending,
            rescanAttemptedAt: Carbon::now()->subHours(100)->toDateTimeString(),
        );

        $this->assertSame(
            [2, 1],
            RescanCandidateQuery::batch(10, 95.0, 72)->pluck('id')->map(intval(...))->all()
        );
    }

    #[Test]
    public function a_re_scan_retry_inside_its_window_is_not_selected(): void
    {
        $this->insertRelease(
            1,
            completion: 40.0,
            outcome: null,
            declaredFiles: 40,
            totalPart: 38,
            rescanOutcome: ReleaseRepairOutcome::RetryPending,
            rescanAttemptedAt: Carbon::now()->subHours(71)->toDateTimeString(),
        );

        $this->assertTrue(RescanCandidateQuery::batch(10, 95.0, 72)->isEmpty());
    }

    #[Test]
    public function a_below_target_partial_rescan_is_always_owned_by_recovery_or_the_sweep(): void
    {
        $this->insertRelease(
            1,
            completion: 80.0,
            outcome: ReleaseRepairOutcome::Failed,
            declaredFiles: 40,
            totalPart: 39,
            rescanOutcome: ReleaseRepairOutcome::RetryPending,
            rescanAttemptedAt: Carbon::now()->subHours(100)->toDateTimeString(),
        );
        $this->insertRelease(
            2,
            completion: 80.0,
            outcome: ReleaseRepairOutcome::Failed,
            declaredFiles: 40,
            totalPart: 39,
            rescanOutcome: ReleaseRepairOutcome::Failed,
        );

        $this->assertTrue(ReleaseRepairCandidateQuery::batch(10, 95.0, 72)->isEmpty());
        $this->assertSame(
            [1],
            RescanCandidateQuery::batch(10, 95.0, 72)->pluck('id')->map(intval(...))->all(),
        );
        $this->assertSame([2], $this->sweptIds(95.0));
    }

    #[Test]
    public function repaired_releases_reopen_only_when_the_completion_target_rises(): void
    {
        $this->insertRelease(
            1,
            completion: 96.0,
            outcome: ReleaseRepairOutcome::Repaired,
            repairTargetCompletion: 95.0,
            declaredFiles: 13,
            totalPart: 12,
            rescanOutcome: ReleaseRepairOutcome::Repaired,
            rescanTargetCompletion: 95.0,
        );
        $this->insertRelease(
            2,
            completion: 96.0,
            outcome: ReleaseRepairOutcome::Repaired,
            repairTargetCompletion: 99.0,
            declaredFiles: 13,
            totalPart: 12,
            rescanOutcome: ReleaseRepairOutcome::Repaired,
            rescanTargetCompletion: 99.0,
        );

        $this->assertSame(
            [1],
            ReleaseRepairCandidateQuery::batch(10, 99.0, 72)->pluck('id')->map(intval(...))->all(),
        );
        $this->assertSame(
            [1],
            RescanCandidateQuery::batch(10, 99.0, 72)->pluck('id')->map(intval(...))->all(),
        );

        DB::table('releases')->where('id', 1)->update([
            'repair_evaluated_target_completion' => 99.0,
            'rescan_evaluated_target_completion' => 99.0,
        ]);

        $this->assertTrue(ReleaseRepairCandidateQuery::batch(10, 99.0, 72)->isEmpty());
        $this->assertTrue(RescanCandidateQuery::batch(10, 99.0, 72)->isEmpty());
        $this->assertTrue(ReleaseRepairCandidateQuery::batch(10, 95.0, 72)->isEmpty());
        $this->assertTrue(RescanCandidateQuery::batch(10, 95.0, 72)->isEmpty());
        $this->assertSame([], $this->sweptIds(99.0));
    }

    /**
     * @return list<int>
     */
    private function sweptIds(float $threshold): array
    {
        return IncompleteReleaseSweepQuery::builder($threshold)
            ->orderBy('id')
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }

    private function insertRelease(
        int $id,
        float $completion,
        ?ReleaseRepairOutcome $outcome,
        ?string $attemptedAt = null,
        string $postdate = '2024-01-01 00:00:00',
        ?int $declaredFiles = 0,
        int $totalPart = 0,
        ?ReleaseRepairOutcome $rescanOutcome = null,
        ?string $rescanAttemptedAt = null,
        ?string $additionalClaimedAt = null,
        ?string $recoveryClaimedAt = null,
        ?float $repairTargetCompletion = null,
        ?float $rescanTargetCompletion = null,
        ?float $repairEvaluatedTargetCompletion = null,
        ?float $rescanEvaluatedTargetCompletion = null,
    ): void {
        DB::table('releases')->insert([
            'id' => $id,
            'guid' => sprintf('%032x', $id),
            'nzbstatus' => 1,
            'completion' => $completion,
            'repair_outcome' => $outcome?->value,
            'repair_attempted_at' => $attemptedAt,
            'repair_target_completion' => $repairTargetCompletion,
            'repair_evaluated_target_completion' => $repairEvaluatedTargetCompletion ?? $repairTargetCompletion,
            'rescan_outcome' => $rescanOutcome?->value,
            'rescan_attempted_at' => $rescanAttemptedAt,
            'rescan_target_completion' => $rescanTargetCompletion,
            'rescan_evaluated_target_completion' => $rescanEvaluatedTargetCompletion ?? $rescanTargetCompletion,
            'additional_pp_claimed_at' => $additionalClaimedAt,
            'recovery_claimed_at' => $recoveryClaimedAt,
            'declaredfiles' => $declaredFiles,
            'totalpart' => $totalPart,
            'postdate' => $postdate,
            'haspreview' => -1,
        ]);
    }

    private function createReleasesTable(): void
    {
        DB::statement('DROP TABLE IF EXISTS releases');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(64),
            nzbstatus INTEGER NOT NULL DEFAULT 0,
            completion DOUBLE NOT NULL DEFAULT 0,
            repair_attempted_at DATETIME NULL,
            repair_outcome VARCHAR(16) NULL,
            repair_target_completion DOUBLE NULL,
            repair_evaluated_target_completion DOUBLE NULL,
            rescan_attempted_at DATETIME NULL,
            rescan_outcome VARCHAR(16) NULL,
            rescan_target_completion DOUBLE NULL,
            rescan_evaluated_target_completion DOUBLE NULL,
            additional_pp_claimed_at DATETIME NULL,
            recovery_claimed_at DATETIME NULL,
            declaredfiles INTEGER NULL,
            totalpart INTEGER NOT NULL DEFAULT 0,
            groups_id INTEGER NULL,
            firstarticle INTEGER NULL,
            lastarticle INTEGER NULL,
            postdate DATETIME NULL,
            haspreview INTEGER NOT NULL DEFAULT -1,
            passwordstatus INTEGER NOT NULL DEFAULT -1
        )');
    }
}
