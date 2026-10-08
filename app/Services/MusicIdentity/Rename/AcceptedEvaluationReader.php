<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Rename;

use App\Models\ReleaseMusicCandidateAttempt;
use App\Models\ReleaseMusicIdentification;
use App\Services\MusicIdentity\DTO\AcceptedEvaluation;

/**
 * The one place that answers which candidate evaluation a decision accepted (issue #309). Today
 * that is the resolver's automatic choice, the rank-1 candidate the decision store recorded; a
 * human selection of another candidate (#310) changes only this method.
 */
final class AcceptedEvaluationReader
{
    public function forDecision(ReleaseMusicIdentification $decision): ?AcceptedEvaluation
    {
        if ($decision->accepted_scope === null) {
            return null;
        }

        /** @var ReleaseMusicCandidateAttempt|null $candidate */
        $candidate = $decision->candidateAttempts()->where('rank', 1)->first();
        if ($candidate === null) {
            return null;
        }

        return new AcceptedEvaluation(
            score: $candidate->score,
            runnerUpMargin: $decision->runner_up_margin,
            contradictions: array_values(array_map(strval(...), $candidate->contradictions ?? [])),
        );
    }
}
