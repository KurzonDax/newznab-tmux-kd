<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Enums\ReleaseRepairOutcome;

/**
 * What one release's header re-scan did.
 */
final readonly class MissingFileRescanResult
{
    /**
     * @param  ReleaseRepairOutcome|null  $outcome  Null when the pass could not run at all and the
     *                                              release's re-scan state was left untouched.
     * @param  int  $articlesRequested  The width of the window that was read, or would have been.
     * @param  int  $overviewLinesFetched  Lines the server actually returned -- what the per-run
     *                                     budget is spent against.
     */
    public function __construct(
        public ?ReleaseRepairOutcome $outcome,
        public float $completionBefore,
        public float $completionAfter,
        public int $declaredFiles,
        public int $filesHeld,
        public int $filesRecovered,
        public int $segmentsAdded,
        public int $articlesRequested,
        public int $overviewLinesFetched,
        public bool $nzbRewritten,
        public string $reason,
    ) {}

    /**
     * The pass could not run: something about *our* side was broken, not the release.
     *
     * A missing NZB, an unreadable one, a group the server will not select. None of those say
     * anything about whether the release's articles are still out there, so they must not advance
     * the state machine toward a deletable outcome -- the release is simply seen again next run.
     */
    public static function notAttempted(float $completion, string $reason): self
    {
        return new self(
            outcome: null,
            completionBefore: $completion,
            completionAfter: $completion,
            declaredFiles: 0,
            filesHeld: 0,
            filesRecovered: 0,
            segmentsAdded: 0,
            articlesRequested: 0,
            overviewLinesFetched: 0,
            nzbRewritten: false,
            reason: $reason,
        );
    }

    public function withOutcome(ReleaseRepairOutcome $outcome): self
    {
        return new self(
            outcome: $outcome,
            completionBefore: $this->completionBefore,
            completionAfter: $this->completionAfter,
            declaredFiles: $this->declaredFiles,
            filesHeld: $this->filesHeld,
            filesRecovered: $this->filesRecovered,
            segmentsAdded: $this->segmentsAdded,
            articlesRequested: $this->articlesRequested,
            overviewLinesFetched: $this->overviewLinesFetched,
            nzbRewritten: $this->nzbRewritten,
            reason: $this->reason,
        );
    }
}
