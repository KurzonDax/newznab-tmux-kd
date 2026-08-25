<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Enums\ReleaseRepairOutcome;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\NNTP\NntpProviderPool;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Recovers releases whose headers were missed but whose articles are still on the provider.
 *
 * A sub-threshold `completion` usually means the header scan missed some articles, not that the
 * post is gone. Where the poster numbers its message-IDs (PowerPost and friends), the IDs of the
 * missing segments are derivable from the ones we did see, verifiable with a STAT, and can be
 * written straight back into the stored NZB.
 *
 * Every release gets at most two network passes, and only when both have been spent does the
 * completion sweep get to touch it -- see {@see ReleaseRepairOutcome}.
 */
final class ReleaseRepairService
{
    public function __construct(
        private readonly NzbService $nzb,
        private readonly NntpProviderPool $pool,
        private readonly NzbParserService $parser = new NzbParserService,
    ) {}

    /**
     * Run one repair pass over a release and record its outcome.
     *
     * @throws \Exception
     */
    public function repair(Release $release, ReleaseRepairOptions $options): ReleaseRepairResult
    {
        $lease = RecoveryLease::acquire($release);

        if ($lease === null) {
            return ReleaseRepairResult::notAttempted(
                (float) $release->completion,
                'Another recovery pass is already working this release.',
            );
        }

        try {
            return $this->repairWithLease($release, $options);
        } finally {
            $lease->release();
        }
    }

    /**
     * @throws \Exception
     */
    private function repairWithLease(Release $release, ReleaseRepairOptions $options): ReleaseRepairResult
    {
        $completionBefore = (float) $release->completion;
        $isFinalAttempt = $release->repair_outcome === ReleaseRepairOutcome::RetryPending;

        if ($completionBefore < $options->floorCompletion) {
            // Below the floor, so no articles are probed at all: this outcome is final on the
            // first sight of the release, without ever touching the network.
            return $this->finish($release, $options, new ReleaseRepairResult(
                outcome: $release->repair_outcome === ReleaseRepairOutcome::Repaired
                    ? ReleaseRepairOutcome::Repaired
                    : ReleaseRepairOutcome::SkippedFloor,
                completionBefore: $completionBefore,
                completionAfter: $completionBefore,
                segmentsAdded: 0,
                articlesProbed: 0,
                nzbRewritten: false,
                requeuedForAdditionalProcessing: false,
                reason: sprintf('Below the %.1f%% repair floor.', $options->floorCompletion),
            ));
        }

        $contents = $this->nzb->readNzbContents((string) $release->guid);

        if ($contents === false) {
            // Says nothing about the release: an unmounted volume looks exactly like this.
            return $this->skip($release, ReleaseRepairResult::notAttempted($completionBefore, 'No NZB on disk to repair.'));
        }

        $document = NzbRepairDocument::load($contents, $this->parser);

        if ($document === null) {
            return $this->skip($release, ReleaseRepairResult::notAttempted($completionBefore, 'Stored NZB could not be parsed.'));
        }

        $plan = $document->plan();

        if (! $plan->hasWork()) {
            $completionAfter = $document->measure()->percentage();
            $outcome = $this->outcomeFor($release, $completionAfter, $options->targetCompletion, $isFinalAttempt);

            return $this->finish($release, $options, new ReleaseRepairResult(
                outcome: $outcome,
                completionBefore: $completionBefore,
                completionAfter: $completionAfter,
                segmentsAdded: 0,
                articlesProbed: 0,
                nzbRewritten: false,
                requeuedForAdditionalProcessing: false,
                reason: $this->describeUnrecoverable($plan),
            ), $completionAfter);
        }

        [$accepted, $probes] = $this->verify($plan, $options);

        if ($accepted === []) {
            return $this->finish($release, $options, $this->unrepaired(
                $release,
                $completionBefore,
                $isFinalAttempt,
                'No synthesized message-ID could be confirmed on any provider.',
                $probes,
            ));
        }

        $added = $document->addSegments($accepted);
        $completionAfter = $document->measure()->percentage();
        $rewritten = false;

        if (! $options->dryRun) {
            $rewritten = $this->nzb->replaceNzbContents((string) $release->guid, $document->toXml());

            if (! $rewritten) {
                // We know what to write and could not write it. That is our problem, not the
                // release's: leave its state alone so the next invocation tries again.
                return $this->skip($release, ReleaseRepairResult::notAttempted(
                    $completionBefore,
                    'Repaired NZB could not be written back to disk.',
                ));
            }
        }

        $outcome = $this->outcomeFor($release, $completionAfter, $options->targetCompletion, $isFinalAttempt);

        $requeued = ! $options->dryRun
            && $added > 0
            && $this->requeueForAdditionalProcessing($release);

        return $this->finish($release, $options, new ReleaseRepairResult(
            outcome: $outcome,
            completionBefore: $completionBefore,
            completionAfter: $completionAfter,
            segmentsAdded: $added,
            articlesProbed: $probes,
            nzbRewritten: $rewritten,
            requeuedForAdditionalProcessing: $requeued,
            reason: sprintf('Added %d verified segment(s).', $added),
        ), $completionAfter);
    }

    /**
     * Spot-check synthesized message-IDs, one file at a time.
     *
     * A whole file is accepted only when every ID sampled from it exists somewhere in the pool.
     * Nothing unverified goes into an NZB: a wrong template would fill the file with IDs that
     * fail at download time, which is worse than leaving it short. A file that gets no probe
     * budget is left alone for the same reason.
     *
     * @return array{0: array<int, array<int, string>>, 1: int} Accepted segments by file index, and probes spent.
     *
     * @throws \Exception
     */
    private function verify(ReleaseRepairPlan $plan, ReleaseRepairOptions $options): array
    {
        $accepted = [];
        $probes = 0;

        foreach ($plan->files as $file) {
            $sample = $file->verificationSample($options->statSamplePerFile);

            if ($sample === []) {
                continue;
            }

            if (count($sample) > $options->maxStatProbes - $probes) {
                // Not enough budget left to sample this file properly. Stop rather than accept
                // it on a thinner sample than the rest: one confirmation is a much weaker
                // argument against a wrong template than two, and the leftover files keep their
                // missing segments for the next pass.
                break;
            }

            $confirmed = true;

            foreach ($sample as $number) {
                $probes++;

                if (! $this->pool->articleExists($file->synthesized[$number])) {
                    $confirmed = false;

                    break;
                }
            }

            if ($confirmed) {
                $accepted[$file->fileIndex] = $file->synthesized;
            }
        }

        return [$accepted, $probes];
    }

    /**
     * Re-open a repaired release to additional processing -- but only when it could gain from it.
     *
     * The reset costs an AP slot, so it is spent only where repair actually added segments and
     * the release has nothing to show for its previous pass. A release that already carries
     * media info or a preview is one AP cannot improve, and re-queuing it would displace a fresh
     * release that could be.
     */
    private function requeueForAdditionalProcessing(Release $release): bool
    {
        if ($release->hasProcessingArtifacts()) {
            return false;
        }

        Release::query()->where('id', $release->id)->update(ReleaseClaimant::rependValues());

        return true;
    }

    /**
     * The outcome for a pass that ran and could not add anything: one more chance, or the end.
     */
    private function unrepaired(
        Release $release,
        float $completionBefore,
        bool $isFinalAttempt,
        string $reason,
        int $probes = 0,
    ): ReleaseRepairResult {
        return new ReleaseRepairResult(
            outcome: $release->repair_outcome === ReleaseRepairOutcome::Repaired
                ? ReleaseRepairOutcome::Repaired
                : ($isFinalAttempt ? ReleaseRepairOutcome::Failed : ReleaseRepairOutcome::RetryPending),
            completionBefore: $completionBefore,
            completionAfter: $completionBefore,
            segmentsAdded: 0,
            articlesProbed: $probes,
            nzbRewritten: false,
            requeuedForAdditionalProcessing: false,
            reason: $reason,
        );
    }

    private function outcomeFor(
        Release $release,
        float $completionAfter,
        float $targetCompletion,
        bool $isFinalAttempt,
    ): ReleaseRepairOutcome {
        if ($completionAfter >= $targetCompletion || $release->repair_outcome === ReleaseRepairOutcome::Repaired) {
            return ReleaseRepairOutcome::Repaired;
        }

        return $isFinalAttempt ? ReleaseRepairOutcome::Failed : ReleaseRepairOutcome::RetryPending;
    }

    private function describeUnrecoverable(ReleaseRepairPlan $plan): string
    {
        if ($plan->filesWithoutTemplate > 0) {
            return sprintf(
                '%d file(s) use unguessable message-IDs (random-ID poster).',
                $plan->filesWithoutTemplate,
            );
        }

        if ($plan->filesWithNoSegments > 0) {
            return sprintf(
                '%d file(s) have no segment to derive a message-ID pattern from.',
                $plan->filesWithNoSegments,
            );
        }

        return 'Nothing in the NZB is missing a derivable segment.';
    }

    /**
     * Stamp the attempt and its outcome. Both columns are written together, always.
     */
    private function finish(
        Release $release,
        ReleaseRepairOptions $options,
        ReleaseRepairResult $result,
        ?float $completion = null,
    ): ReleaseRepairResult {
        if ($options->dryRun || $result->outcome === null) {
            return $result;
        }

        $preservesEarlierAchievement = $release->repair_outcome === ReleaseRepairOutcome::Repaired
            && $result->outcome === ReleaseRepairOutcome::Repaired
            && $result->completionAfter < $options->targetCompletion;

        $values = [
            'repair_attempted_at' => Carbon::now(),
            'repair_outcome' => $result->outcome->value,
            'repair_target_completion' => $result->outcome === ReleaseRepairOutcome::Repaired
                ? ($preservesEarlierAchievement
                    ? $release->repair_target_completion
                    : $options->targetCompletion)
                : null,
            'repair_evaluated_target_completion' => $options->targetCompletion,
        ];

        if ($completion !== null) {
            $values['completion'] = $completion;
        }

        Release::query()->where('id', $release->id)->update($values);

        Log::debug('Release repair pass finished', [
            'release_id' => $release->id,
            'outcome' => $result->outcome->value,
            'completion_before' => $result->completionBefore,
            'completion_after' => $result->completionAfter,
            'segments_added' => $result->segmentsAdded,
            'articles_probed' => $result->articlesProbed,
            'reason' => $result->reason,
        ]);

        return $result;
    }

    /**
     * Record that the pass could not run, leaving the release's repair state untouched.
     */
    private function skip(Release $release, ReleaseRepairResult $result): ReleaseRepairResult
    {
        Log::warning('Release repair pass could not run', [
            'release_id' => $release->id,
            'reason' => $result->reason,
        ]);

        return $result;
    }
}
