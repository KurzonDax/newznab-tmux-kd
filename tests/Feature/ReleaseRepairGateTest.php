<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReleaseRepairOutcome;
use App\Services\ReleaseRepair\ReleaseRepairCandidateQuery;
use App\Services\Releases\IncompleteReleaseSweepQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * The invariant the whole issue exists for: the completion sweep never deletes a release the
 * repair engine has not finished with.
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
        $this->assertSame(['failed', 'skipped-floor'], ReleaseRepairOutcome::deletableValues());
        $this->assertFalse(ReleaseRepairOutcome::Repaired->isFinal());
        $this->assertFalse(ReleaseRepairOutcome::RetryPending->isFinal());
        $this->assertTrue(ReleaseRepairOutcome::Failed->isFinal());
        $this->assertTrue(ReleaseRepairOutcome::SkippedFloor->isFinal());
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
    ): void {
        DB::table('releases')->insert([
            'id' => $id,
            'guid' => sprintf('%032x', $id),
            'nzbstatus' => 1,
            'completion' => $completion,
            'repair_outcome' => $outcome?->value,
            'repair_attempted_at' => $attemptedAt,
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
            postdate DATETIME NULL,
            haspreview INTEGER NOT NULL DEFAULT -1,
            passwordstatus INTEGER NOT NULL DEFAULT -1
        )');
    }
}
