<?php

declare(strict_types=1);

namespace Tests\Unit\DecisionModel;

use App\Services\Categorization\CategorizationService;
use App\Services\DecisionModel\DecisionLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Support\ArbitratesCategoriesInShadow;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * Linking a decision made before its subject was stored to that subject.
 */
final class DecisionLogTest extends TestCase
{
    use ArbitratesCategoriesInShadow;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        ProductionTables::fromAuthority()->create('decision_model_criteria', null);
        ProductionTables::fromAuthority()->create('decision_model_decisions', null);
        DB::table('decision_model_criteria')->insert(['id' => 1, 'use_case' => 'category_arbitration', 'version' => 'invented']);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_an_unlinked_decision_is_linked_to_its_subject(): void
    {
        $decisionId = $this->decision();

        (new DecisionLog)->attachSubject($decisionId, 41);

        $this->assertSame(41, $this->subjectOf($decisionId));
    }

    public function test_a_linked_decision_keeps_its_subject(): void
    {
        $decisionId = $this->decision(subjectId: 7);

        (new DecisionLog)->attachSubject($decisionId, 41);
        (new DecisionLog)->attachSubjectAfterCommit($decisionId, 42);

        $this->assertSame(7, $this->subjectOf($decisionId));
    }

    public function test_a_decision_that_is_gone_is_left_alone(): void
    {
        $existing = $this->decision();

        (new DecisionLog)->attachSubject($existing + 1, 41);
        (new DecisionLog)->attachSubjectAfterCommit($existing + 1, 41);

        $this->assertNull($this->subjectOf($existing));
        $this->assertSame(1, DB::table('decision_model_decisions')->count());
    }

    public function test_outside_a_transaction_the_link_is_made_at_once(): void
    {
        $decisionId = $this->decision();

        (new DecisionLog)->attachSubjectAfterCommit($decisionId, 41);

        $this->assertSame(41, $this->subjectOf($decisionId));
    }

    public function test_inside_a_transaction_the_link_waits_for_the_commit(): void
    {
        $decisionId = $this->decision();

        DB::transaction(function () use ($decisionId): void {
            (new DecisionLog)->attachSubjectAfterCommit($decisionId, 41);

            $this->assertNull($this->subjectOf($decisionId));
        });

        $this->assertSame(41, $this->subjectOf($decisionId));
    }

    public function test_a_rolled_back_transaction_discards_the_link(): void
    {
        $decisionId = $this->decision();

        try {
            DB::transaction(static function () use ($decisionId): void {
                (new DecisionLog)->attachSubjectAfterCommit($decisionId, 41);

                throw new RuntimeException('invented rollback');
            });
        } catch (RuntimeException) {
        }
        DB::transaction(static function (): void {});

        $this->assertNull($this->subjectOf($decisionId));
    }

    public function test_a_link_that_cannot_be_written_is_reported_and_never_thrown(): void
    {
        $decisionId = $this->decision();
        $this->failDecisionLinking();
        Log::shouldReceive('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => $message === 'decision_model.attach_subject_failed'
                && $context['decision_id'] === $decisionId
                && $context['subject_id'] === 41,
        );

        DB::transaction(static function () use ($decisionId): void {
            (new DecisionLog)->attachSubjectAfterCommit($decisionId, 41);
        });

        $this->assertNull($this->subjectOf($decisionId));
    }

    public function test_a_report_that_cannot_be_written_is_not_thrown_either(): void
    {
        $decisionId = $this->decision();
        $this->failDecisionLinking();
        Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('invented log failure'));

        (new DecisionLog)->attachSubjectAfterCommit($decisionId, 41);

        $this->assertNull($this->subjectOf($decisionId));
    }

    public function test_a_categorization_without_a_decision_links_nothing(): void
    {
        $decisionId = $this->decision();
        $updates = 0;
        DB::listen(static function ($query) use (&$updates): void {
            $updates += str_starts_with(strtolower($query->sql), 'update') ? 1 : 0;
        });

        (new CategorizationService)->attachDecisionToRelease(['categories_id' => 10], 41);

        $this->assertSame(0, $updates);
        $this->assertNull($this->subjectOf($decisionId));
    }

    public function test_a_categorization_with_a_decision_links_it(): void
    {
        $decisionId = $this->decision();

        (new CategorizationService)->attachDecisionToRelease(['categories_id' => 10, 'decision_id' => $decisionId], 41);

        $this->assertSame(41, $this->subjectOf($decisionId));
    }

    private function decision(?int $subjectId = null): int
    {
        return (int) DB::table('decision_model_decisions')->insertGetId([
            'decision_model_criteria_id' => 1,
            'subject_type' => 'release',
            'subject_id' => $subjectId,
            'mode' => 'shadow',
            'state' => '{"release_name":"An Invented Evening"}',
            'applied' => false,
            'created_at' => now(),
        ]);
    }

    private function subjectOf(int $decisionId): ?int
    {
        $subjectId = DB::table('decision_model_decisions')->where('id', $decisionId)->value('subject_id');

        return $subjectId === null ? null : (int) $subjectId;
    }
}
