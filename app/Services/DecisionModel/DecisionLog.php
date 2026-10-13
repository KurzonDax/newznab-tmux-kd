<?php

declare(strict_types=1);

namespace App\Services\DecisionModel;

use App\Models\DecisionModelCriteria;
use App\Models\DecisionModelDecision;

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
