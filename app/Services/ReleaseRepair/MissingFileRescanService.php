<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Enums\ReleaseRepairOutcome;
use App\Models\Release;
use App\Models\UsenetGroup;
use App\Services\NNTP\NntpProviderPool;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Recovers files the header scan missed *entirely*.
 *
 * The repair engine ({@see ReleaseRepairService}) works from what an NZB already holds: given two
 * segments of a file it can derive the message-IDs of the rest. A file with no seen header has no
 * `binaries` row, so it never became a `<file>` element at all -- there is nothing in the NZB to
 * notice its absence by and nothing to derive a pattern from. The only way back to it is the
 * group's own headers.
 *
 * So this pass reads a window of the group around where the collection is known to have been,
 * matches overview lines against the release strictly (see {@see MissingFileMatcher}), and writes
 * the ones that belong into the stored NZB as new files. Partially found files are written with
 * what was found: the repair engine's next pass synthesizes the rest from the message-IDs this
 * one supplies, which is why a partial recovery is worth writing at all.
 *
 * Header traffic is primary-pinned by construction -- article numbers are per-server, so this
 * takes an {@see NNTPService} directly and {@see NntpProviderPool} deliberately
 * exposes no header API to reach instead.
 *
 * ## When a pass records nothing
 *
 * The completion sweep waits for a final outcome here, so anything left unstamped is a release
 * the reaper can never touch. That makes "record nothing" a narrower category than it looks, and
 * the line runs between *our* side and the *release's*:
 *
 * - **Our storage failed** -- no NZB on disk, an unparseable one, one we could not write back.
 *   Records nothing. Two unmounted volumes in a row must not add up to a verdict, and the release
 *   is simply seen again next run. Same rule {@see ReleaseRepairService} follows.
 * - **The run ran out of budget** before reaching the release, or before its complete window was
 *   read. Records no negative verdict: an unread tail may still contain the missing headers.
 *   Matches from the read portion remain useful and are persisted.
 * - **The release can never be re-scanned** -- it points at a group row that is gone, declares no
 *   more files than it holds, has no window to aim at, or holds files with no file index to place
 *   a recovered one against. Stamped final on sight: none of those read differently on a later
 *   pass, and leaving them null would quietly make the release undeletable for good.
 * - **The provider gave a verdict** -- it would not select the group. Runs the normal two passes,
 *   so a dropped group settles at `failed` while a sulking connection gets its retry window first.
 */
final class MissingFileRescanService
{
    public function __construct(
        private readonly NzbService $nzb,
        private readonly NNTPService $nntp,
        private readonly RescanWindowResolver $windowResolver,
        private readonly DeclaredFileCount $declaredFileCount = new DeclaredFileCount,
        private readonly NzbParserService $parser = new NzbParserService,
        private readonly EvidenceChangedTransition $evidenceChanged = new EvidenceChangedTransition,
    ) {}

    /**
     * Run one re-scan pass over a release and record its outcome.
     *
     * @throws \Exception
     */
    public function rescan(Release $release, MissingFileRescanOptions $options, RescanRunBudget $budget): MissingFileRescanResult
    {
        $lease = RecoveryLease::acquire($release);

        if ($lease === null) {
            return MissingFileRescanResult::notAttempted(
                (float) $release->completion,
                'Another recovery pass is already working this release.',
            );
        }

        try {
            return $this->rescanWithLease($release, $options, $budget);
        } finally {
            $lease->release();
        }
    }

    /**
     * @throws \Exception
     */
    private function rescanWithLease(
        Release $release,
        MissingFileRescanOptions $options,
        RescanRunBudget $budget,
    ): MissingFileRescanResult {
        $completionBefore = (float) $release->completion;
        $isFinalAttempt = $release->rescan_outcome === ReleaseRepairOutcome::RetryPending;

        $contents = $this->nzb->readNzbContents((string) $release->guid);

        if ($contents === false) {
            // Says nothing about the release: an unmounted volume looks exactly like this.
            return $this->skip($release, MissingFileRescanResult::notAttempted($completionBefore, 'No NZB on disk to re-scan.'));
        }

        $document = NzbRepairDocument::load($contents, $this->parser);
        $envelope = $document?->envelope();

        if ($document === null || $envelope === null) {
            return $this->skip($release, MissingFileRescanResult::notAttempted($completionBefore, 'Stored NZB could not be parsed.'));
        }

        $subjects = array_values($document->subjects());
        $held = \count($subjects);
        $declared = $this->declaredFileCount->resolve($release, $document, persist: ! $options->dryRun);
        $heldIndices = array_values(array_filter(array_map(
            static fn (string $subject): ?int => MissingFileMatcher::fileIndexOf($subject),
            $subjects,
        ), static fn (?int $index): bool => $index !== null));

        $nothingToRescan = $this->describeNothingToRescan($declared, $held, \count($heldIndices));

        if ($nothingToRescan !== null) {
            return $this->finish($release, $options, $this->plain(
                ReleaseRepairOutcome::SkippedFloor,
                $completionBefore,
                $declared,
                $held,
                $nothingToRescan,
            ));
        }

        $group = UsenetGroup::query()->find($release->groups_id);

        if ($group === null) {
            // The release points at a group row that is gone. Nothing will put it back, so this
            // is a verdict rather than a blip -- see the note on finality above.
            return $this->finish($release, $options, $this->plain(
                ReleaseRepairOutcome::SkippedFloor,
                $completionBefore,
                $declared,
                $held,
                'The release points at a group that no longer exists.',
            ));
        }

        $groupNntp = $this->nntp->selectGroup((string) $group->name);

        if (NNTPService::isError($groupNntp) || ! \is_array($groupNntp)) {
            // The provider will not give us the group. That may be a dropped group or a sulking
            // connection, and we cannot tell them apart from here -- so it runs through the same
            // two passes everything else does. A genuinely dropped group settles at `failed` on
            // the second; a blip gets its retry window first. It is a statement about the
            // provider, unlike the storage faults above, which is what makes it stampable.
            return $this->finish($release, $options, $this->unrecovered(
                $completionBefore,
                $isFinalAttempt,
                $declared,
                $held,
                sprintf('Group %s could not be selected on the primary provider.', $group->name),
            ));
        }

        $window = $this->windowResolver->resolve($release, $group, $groupNntp, $options->windowMinutes);

        if ($window === null) {
            // No anchors and no usable postdate. Neither will ever arrive, so there is nothing to
            // aim a window at on this pass or any later one.
            return $this->finish($release, $options, $this->plain(
                ReleaseRepairOutcome::SkippedFloor,
                $completionBefore,
                $declared,
                $held,
                'No article anchors and no usable postdate to derive a window from.',
            ));
        }

        if ($window->width() > $options->maxArticlesPerRelease) {
            return $this->finish($release, $options, new MissingFileRescanResult(
                outcome: ReleaseRepairOutcome::SkippedBudget,
                completionBefore: $completionBefore,
                completionAfter: $completionBefore,
                declaredFiles: $declared,
                filesHeld: $held,
                filesRecovered: 0,
                segmentsAdded: 0,
                articlesRequested: $window->width(),
                overviewLinesFetched: 0,
                nzbRewritten: false,
                reason: sprintf(
                    'Window of %s articles exceeds the %s per-release ceiling.',
                    number_format($window->width()),
                    number_format($options->maxArticlesPerRelease),
                ),
            ));
        }

        if ($options->dryRun) {
            return MissingFileRescanResult::notAttempted(
                $completionBefore,
                sprintf(
                    'Would read %s %s articles (%d-%d) for %d missing file(s).',
                    number_format($window->width()),
                    $window->anchored ? 'anchored' : 'bisected',
                    $window->first,
                    $window->last,
                    $declared - $held,
                ),
            );
        }

        if ($budget->isExhausted()) {
            return $this->skip($release, MissingFileRescanResult::notAttempted(
                $completionBefore,
                'The run budget was spent before this release was reached.',
            ));
        }

        [$matched, $linesFetched, $fetchFailed, $windowReadCompletely] = $this->fetch($window, $options, $budget, new MissingFileMatcher(
            poster: $envelope->poster,
            declaredFiles: $declared,
            heldSubjects: $subjects,
            heldIndices: $heldIndices,
        ));

        if ($matched === []) {
            if ($fetchFailed) {
                return $this->skip($release, MissingFileRescanResult::notAttempted(
                    $completionBefore,
                    'The overview fetch failed before anything was matched.',
                ));
            }

            if (! $windowReadCompletely) {
                return $this->skip($release, new MissingFileRescanResult(
                    outcome: null,
                    completionBefore: $completionBefore,
                    completionAfter: $completionBefore,
                    declaredFiles: $declared,
                    filesHeld: $held,
                    filesRecovered: 0,
                    segmentsAdded: 0,
                    articlesRequested: $window->width(),
                    overviewLinesFetched: $linesFetched,
                    nzbRewritten: false,
                    reason: 'The run budget was spent before the release window was fully read.',
                ));
            }

            return $this->finish($release, $options, $this->unrecovered(
                $completionBefore,
                $isFinalAttempt,
                $declared,
                $held,
                'No header in the window belongs to this release.',
                $window->width(),
                $linesFetched,
            ));
        }

        $recovered = $this->buildFiles($matched);
        $added = $document->addFiles($recovered, $envelope);
        $completionAfter = $document->measure($declared)->percentage();

        if (! $this->nzb->replaceNzbContents((string) $release->guid, $document->toXml())) {
            // We know what to write and could not write it. That is our problem, not the
            // release's: leave its state alone so the next invocation tries again.
            return $this->skip($release, MissingFileRescanResult::notAttempted(
                $completionBefore,
                'Re-scanned NZB could not be written back to disk.',
            ));
        }

        if ($added > 0) {
            $this->evidenceChanged->apply($release, $document, $declared);
        }

        $outcome = match (true) {
            $completionAfter >= $options->targetCompletion => ReleaseRepairOutcome::Repaired,
            ! $windowReadCompletely => null,
            $isFinalAttempt => ReleaseRepairOutcome::Failed,
            default => ReleaseRepairOutcome::RetryPending,
        };

        return $this->finish($release, $options, new MissingFileRescanResult(
            outcome: $outcome,
            completionBefore: $completionBefore,
            completionAfter: $completionAfter,
            declaredFiles: $declared,
            filesHeld: $held,
            filesRecovered: \count($recovered),
            segmentsAdded: $added,
            articlesRequested: $window->width(),
            overviewLinesFetched: $linesFetched,
            nzbRewritten: true,
            reason: match (true) {
                $windowReadCompletely => sprintf('Recovered %d file(s), %d segment(s).', \count($recovered), $added),
                $fetchFailed => sprintf(
                    'Recovered %d file(s), %d segment(s) before the overview fetch failed.',
                    \count($recovered),
                    $added,
                ),
                default => sprintf(
                    'Recovered %d file(s), %d segment(s) before the run budget was spent.',
                    \count($recovered),
                    $added,
                ),
            },
        ), $completionAfter);
    }

    /**
     * Read the window in XOVER-sized batches, keeping only what belongs to this release.
     *
     * @return array{0: array<int, array<int, OverviewLine>>, 1: int, 2: bool, 3: bool} Matched lines
     *                                                                                  by file index and
     *                                                                                  segment number,
     *                                                                                  lines read, whether
     *                                                                                  a fetch errored, and
     *                                                                                  whether the complete
     *                                                                                  window was read.
     *
     * @throws \Exception
     */
    private function fetch(
        RescanWindow $window,
        MissingFileRescanOptions $options,
        RescanRunBudget $budget,
        MissingFileMatcher $matcher,
    ): array {
        $matched = [];
        $linesFetched = 0;
        $batch = max(1, $options->overviewBatchSize);
        $windowReadCompletely = true;

        for ($start = $window->first; $start <= $window->last; $start += $batch) {
            if ($budget->isExhausted()) {
                $windowReadCompletely = false;

                break;
            }

            $end = min($start + $batch - 1, $window->last);
            $headers = $this->nntp->getXOVER($start.'-'.$end);

            if (NNTPService::isError($headers) || ! \is_array($headers)) {
                return [$matched, $linesFetched, true, false];
            }

            $linesFetched += \count($headers);
            $budget->spend(\count($headers));

            foreach ($headers as $header) {
                if (! \is_array($header)) {
                    continue;
                }

                $line = OverviewLine::parse($header);

                if ($line === null || ! $matcher->matches($line)) {
                    continue;
                }

                $matched[$line->fileIndex][$line->segmentNumber] = $line;
            }
        }

        return [$matched, $linesFetched, false, $windowReadCompletely];
    }

    /**
     * @param  array<int, array<int, OverviewLine>>  $matched
     * @return array<int, RecoveredFile>
     */
    private function buildFiles(array $matched): array
    {
        $files = [];

        foreach ($matched as $fileIndex => $lines) {
            ksort($lines);
            $first = reset($lines);

            if ($first === false) {
                continue;
            }

            $segments = [];

            foreach ($lines as $number => $line) {
                $segments[$number] = new RecoveredSegment($line->messageId, $line->bytes);
            }

            $files[$fileIndex] = new RecoveredFile($fileIndex, $first->nzbSubject(), $segments);
        }

        return $files;
    }

    /**
     * Why this release has nothing worth spending a window on, or null when it has.
     */
    private function describeNothingToRescan(int $declared, int $held, int $indexedFiles): ?string
    {
        if ($declared <= 0) {
            return 'Nothing in the stored NZB declares a file count.';
        }

        if ($declared <= $held) {
            return sprintf('Holds %d of the %d file(s) it declares.', $held, $declared);
        }

        if ($indexedFiles !== $held) {
            // Without a file index on every held file we cannot tell which indices are already
            // ours, and appending one we already have would give the release the same file twice.
            return sprintf('%d of %d held file(s) carry no file index.', $held - $indexedFiles, $held);
        }

        return null;
    }

    /**
     * The outcome for a pass that ran and recovered nothing: one more chance, or the end.
     */
    private function unrecovered(
        float $completion,
        bool $isFinalAttempt,
        int $declared,
        int $held,
        string $reason,
        int $articlesRequested = 0,
        int $overviewLinesFetched = 0,
    ): MissingFileRescanResult {
        return new MissingFileRescanResult(
            outcome: $isFinalAttempt ? ReleaseRepairOutcome::Failed : ReleaseRepairOutcome::RetryPending,
            completionBefore: $completion,
            completionAfter: $completion,
            declaredFiles: $declared,
            filesHeld: $held,
            filesRecovered: 0,
            segmentsAdded: 0,
            articlesRequested: $articlesRequested,
            overviewLinesFetched: $overviewLinesFetched,
            nzbRewritten: false,
            reason: $reason,
        );
    }

    private function plain(
        ReleaseRepairOutcome $outcome,
        float $completion,
        int $declared,
        int $held,
        string $reason,
    ): MissingFileRescanResult {
        return new MissingFileRescanResult(
            outcome: $outcome,
            completionBefore: $completion,
            completionAfter: $completion,
            declaredFiles: $declared,
            filesHeld: $held,
            filesRecovered: 0,
            segmentsAdded: 0,
            articlesRequested: 0,
            overviewLinesFetched: 0,
            nzbRewritten: false,
            reason: $reason,
        );
    }

    /**
     * Stamp the attempt and its outcome. Both columns are written together, always.
     */
    private function finish(
        Release $release,
        MissingFileRescanOptions $options,
        MissingFileRescanResult $result,
        ?float $completion = null,
    ): MissingFileRescanResult {
        if ($release->rescan_outcome === ReleaseRepairOutcome::Repaired
            && $result->outcome !== null
            && $result->outcome !== ReleaseRepairOutcome::Repaired) {
            $result = $result->withOutcome(ReleaseRepairOutcome::Repaired);
        }

        if ($options->dryRun) {
            return $result;
        }

        $values = [];

        if ($result->outcome !== null) {
            $preservesEarlierAchievement = $release->rescan_outcome === ReleaseRepairOutcome::Repaired
                && $result->outcome === ReleaseRepairOutcome::Repaired
                && $result->completionAfter < $options->targetCompletion;

            $values = [
                'rescan_attempted_at' => Carbon::now(),
                'rescan_outcome' => $result->outcome->value,
                'rescan_target_completion' => $result->outcome === ReleaseRepairOutcome::Repaired
                    ? ($preservesEarlierAchievement
                        ? $release->rescan_target_completion
                        : $options->targetCompletion)
                    : null,
                'rescan_evaluated_target_completion' => $options->targetCompletion,
            ];
        }

        if ($completion !== null) {
            $values['completion'] = $completion;

            if ($completion >= $options->targetCompletion
                && $release->repair_outcome === ReleaseRepairOutcome::RetryPending) {
                $values['repair_outcome'] = ReleaseRepairOutcome::Repaired->value;
                $values['repair_target_completion'] = $options->targetCompletion;
                $values['repair_evaluated_target_completion'] = $options->targetCompletion;
            }
        }

        Release::query()->where('id', $release->id)->update($values);

        if ($result->outcome === null) {
            Log::warning('Release header re-scan stopped before reading the complete window', [
                'release_id' => $release->id,
                'completion_after' => $result->completionAfter,
                'files_recovered' => $result->filesRecovered,
                'segments_added' => $result->segmentsAdded,
                'overview_lines' => $result->overviewLinesFetched,
                'reason' => $result->reason,
            ]);

            return $result;
        }

        Log::debug('Release header re-scan finished', [
            'release_id' => $release->id,
            'outcome' => $result->outcome->value,
            'declared_files' => $result->declaredFiles,
            'files_held' => $result->filesHeld,
            'files_recovered' => $result->filesRecovered,
            'segments_added' => $result->segmentsAdded,
            'overview_lines' => $result->overviewLinesFetched,
            'reason' => $result->reason,
        ]);

        return $result;
    }

    /**
     * Record that the pass could not run, leaving the release's re-scan state untouched.
     */
    private function skip(Release $release, MissingFileRescanResult $result): MissingFileRescanResult
    {
        Log::warning('Release header re-scan could not run', [
            'release_id' => $release->id,
            'reason' => $result->reason,
        ]);

        return $result;
    }
}
