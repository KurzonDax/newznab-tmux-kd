<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReleaseRepairOutcome;
use App\Services\NNTP\NntpProviderPool;
use App\Services\ReleaseRepair\ReleaseRepairCandidateQuery;
use App\Services\ReleaseRepair\ReleaseRepairOptions;
use App\Services\ReleaseRepair\ReleaseRepairService;
use App\Traits\ResolvesOptionalCommandOptions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recover sub-threshold releases before the completion sweep is allowed to delete them.
 *
 * Runs as a recurring scheduled task on a bounded batch: repaired releases flow straight back
 * into additional processing, so a flood here would starve fresh releases of AP capacity. A drip
 * also means the reaper only ever sees final outcomes appear at this rate.
 */
class RepairReleaseCompletion extends Command
{
    use ResolvesOptionalCommandOptions;

    protected $signature = 'releases:repair-completion
        {--limit= : Releases to work on this invocation (default: the repair_limit setting)}
        {--target= : Completion a release must reach to count as repaired (default: the completionpercent setting)}
        {--floor= : Skip network repair below this completion (default: the repair_floor_completion setting)}
        {--retry-after-hours= : How long after a failed first pass the final pass may run (default: the repair_retry_after_hours setting)}
        {--stat-sample= : Synthesized message-IDs to spot-check per file (default: the repair_stat_sample_per_file setting)}
        {--max-probes= : Hard ceiling on STAT probes per release (default: the repair_max_stat_probes setting)}
        {--dry-run : Probe and report, but write no NZB and no repair state}';

    protected $description = 'Rebuild missing NZB segments for incomplete releases so the completion sweep never deletes a recoverable one';

    public function handle(ReleaseRepairService $repairService, NntpProviderPool $pool): int
    {
        $options = $this->resolveOptions();
        $limit = ReleaseRepairOptions::limitFromSettings($this->intOption('limit'));

        if ($options->dryRun) {
            $this->comment('Dry run: articles are probed, but no NZB and no repair state will be written.');
        }

        $candidates = ReleaseRepairCandidateQuery::batch($limit, $options->targetCompletion, $options->retryAfterHours);

        if ($candidates->isEmpty()) {
            $this->info('No releases are waiting for repair.');

            return self::SUCCESS;
        }

        $tally = array_fill_keys(array_column(ReleaseRepairOutcome::cases(), 'value'), 0);
        $notAttempted = 0;
        $segmentsAdded = 0;
        $probes = 0;
        $requeued = 0;

        foreach ($candidates as $release) {
            try {
                $result = $repairService->repair($release, $options);
            } catch (\Throwable $e) {
                // One unrepairable release must not end the batch: leave its state untouched so
                // the next invocation picks it up again.
                Log::warning('Release repair failed', ['release_id' => $release->id, 'error' => $e->getMessage()]);
                $this->warn(sprintf('Release %d: %s', $release->id, $e->getMessage()));

                continue;
            }

            if ($result->outcome === null) {
                // The pass could not run and the release's state was left untouched, so it will
                // be picked up again rather than advancing toward a deletable outcome.
                $notAttempted++;
            } else {
                $tally[$result->outcome->value]++;
            }

            $segmentsAdded += $result->segmentsAdded;
            $probes += $result->articlesProbed;
            $requeued += $result->requeuedForAdditionalProcessing ? 1 : 0;

            if ($this->output->isVerbose()) {
                $this->line(sprintf(
                    'Release %d: %s (%.2f%% -> %.2f%%) %s',
                    $release->id,
                    $result->outcome?->label() ?? 'Not attempted',
                    $result->completionBefore,
                    $result->completionAfter,
                    $result->reason,
                ));
            }
        }

        $pool->quit();

        $rows = array_map(
            static fn (ReleaseRepairOutcome $case): array => [$case->label(), $tally[$case->value]],
            ReleaseRepairOutcome::cases(),
        );
        $rows[] = ['Not attempted (state untouched)', $notAttempted];

        $this->table(['Outcome', 'Releases'], $rows);

        $this->line(sprintf(
            'Worked %d release(s): %d segment(s) added, %d article(s) probed, %d re-queued for additional processing.',
            $candidates->count(),
            $segmentsAdded,
            $probes,
            $requeued,
        ));

        return self::SUCCESS;
    }

    /**
     * Settings first, CLI flags on top: a flag that was not passed must not be read as a zero.
     */
    private function resolveOptions(): ReleaseRepairOptions
    {
        return ReleaseRepairOptions::fromSettings(
            targetCompletion: $this->floatOption('target'),
            floorCompletion: $this->floatOption('floor'),
            retryAfterHours: $this->intOption('retry-after-hours'),
            statSamplePerFile: $this->intOption('stat-sample'),
            maxStatProbes: $this->intOption('max-probes'),
            dryRun: (bool) $this->option('dry-run'),
        );
    }
}
