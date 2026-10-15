<?php

declare(strict_types=1);

namespace App\Services\DecisionModel;

use App\Models\DecisionModelCriteria;
use App\Models\DecisionModelDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one row per decision, whatever the use case.
 */
final class DecisionLog
{
    /**
     * @param  string  $subjectType  What kind of thing was decided about, e.g. "release".
     * @param  int|null  $subjectId  Its id, when it has a stored row.
     * @param  array<string, mixed>|null  $pipelineAnswer  What the application had decided on its own.
     * @param  bool  $applied  Whether the model's answer replaced the application's own.
     */
    public function record(
        DecisionRun $run,
        string $subjectType,
        ?int $subjectId,
        ?array $pipelineAnswer,
        DecisionMode $mode,
        bool $applied,
    ): DecisionModelDecision {
        return DecisionModelDecision::query()->create([
            'decision_model_criteria_id' => $this->criteriaId($run->useCase, $run->criteriaVersion),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'mode' => $mode->value,
            'state' => $run->state,
            'answers' => $run->responses === [] ? null : $run->answers(),
            'pipeline_answer' => $pipelineAnswer,
            'interpretation' => $run->verdict?->toArray(),
            'applied' => $applied,
            'failure_reason' => $run->failureReason,
        ]);
    }

    /**
     * Link a decision made before its subject was stored to the subject's id.
     * A row already linked keeps its subject; a row that is gone is left alone.
     */
    public function attachSubject(int $decisionId, int $subjectId): void
    {
        DecisionModelDecision::query()
            ->whereKey($decisionId)
            ->whereNull('subject_id')
            ->update(['subject_id' => $subjectId]);
    }

    /**
     * Attach once the caller's transaction commits, or at once outside one.
     * A rolled-back transaction discards the attachment with its own work.
     *
     * The link is telemetry: a failure leaves the decision unlinked, is
     * reported, and never reaches the caller, whose work is already done.
     */
    public function attachSubjectAfterCommit(int $decisionId, int $subjectId): void
    {
        try {
            DB::afterCommit(function () use ($decisionId, $subjectId): void {
                try {
                    $this->attachSubject($decisionId, $subjectId);
                } catch (Throwable $e) {
                    $this->reportAttachFailure($decisionId, $subjectId, $e);
                }
            });
        } catch (Throwable $e) {
            $this->reportAttachFailure($decisionId, $subjectId, $e);
        }
    }

    private function reportAttachFailure(int $decisionId, int $subjectId, Throwable $e): void
    {
        try {
            Log::warning('decision_model.attach_subject_failed', [
                'decision_id' => $decisionId,
                'subject_id' => $subjectId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable) {
            // The report is as optional as the link it describes.
        }
    }

    /**
     * Looked up for every decision rather than remembered: a caller may be
     * inside a transaction that rolls back, taking a criteria row created in
     * it away again.
     */
    private function criteriaId(string $useCase, string $version): int
    {
        return (int) DecisionModelCriteria::query()
            ->firstOrCreate(['use_case' => $useCase, 'version' => $version])
            ->id;
    }
}
