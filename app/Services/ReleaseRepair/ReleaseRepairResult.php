<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Enums\ReleaseRepairOutcome;

/**
 * What one release's repair attempt did.
 */
final readonly class ReleaseRepairResult
{
    /**
     * @param  ReleaseRepairOutcome|null  $outcome  Null when the pass could not run at all and
     *                                              the release's repair state was left untouched.
     */
    public function __construct(
        public ?ReleaseRepairOutcome $outcome,
        public float $completionBefore,
        public float $completionAfter,
        public int $segmentsAdded,
        public int $articlesProbed,
        public bool $nzbRewritten,
        public bool $requeuedForAdditionalProcessing,
        public string $reason,
    ) {}

    /**
     * The pass could not run: something about *our* side was broken, not the release.
     *
     * A missing mount or an unwritable NZB directory says nothing about whether the release's
     * articles are still on the provider, so it must not advance the state machine. Left
     * unstamped, the release is simply picked up again next invocation -- which is the whole
     * point of the gate: nothing reaches a deletable outcome without a real repair verdict.
     */
    public static function notAttempted(float $completion, string $reason): self
    {
        return new self(
            outcome: null,
            completionBefore: $completion,
            completionAfter: $completion,
            segmentsAdded: 0,
            articlesProbed: 0,
            nzbRewritten: false,
            requeuedForAdditionalProcessing: false,
            reason: $reason,
        );
    }
}
