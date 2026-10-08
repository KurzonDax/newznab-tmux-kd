<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

/**
 * The evaluation of the candidate a decision accepted, as the rename gate reads it (issue #309):
 * the candidate's score, its margin over the runner-up (null without one) and its contradictions.
 */
final readonly class AcceptedEvaluation
{
    /** @param  list<string>  $contradictions */
    public function __construct(
        public int $score,
        public ?int $runnerUpMargin,
        public array $contradictions,
    ) {}
}
