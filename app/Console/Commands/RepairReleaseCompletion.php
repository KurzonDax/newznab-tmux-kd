<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReleaseRepairOutcome;
use App\Services\NNTP\NntpProviderPool;
use App\Services\ReleaseRepair\ReleaseRepairCandidateQuery;
use App\Services\ReleaseRepair\ReleaseRepairOptions;
use App\Services\ReleaseRepair\ReleaseRepairService;
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
    protected $signature = 'releases:repair-completion
        {--limit=250 : Releases to work on this invocation}
        {--target= : Completion a release must reach to count as repaired (default: the completionpercent setting, or 95)}
        {--floor= : Skip network repair below this completion (default: 10)}
        {--retry-after-hours= : How long after a failed first pass the final pass may run (default: 72)}
        {--stat-sample= : Synthesized message-IDs to spot-check per file (default: 2)}
        {--max-probes= : Hard ceiling on STAT probes per release (default: 20)}
        {--dry-run : Probe and report, but write no NZB and no repair state}';

    protected $description = 'Rebuild missing NZB segments for incomplete releases so the completion sweep never deletes a recoverable one';

    public function handle(ReleaseRepairService $repairService, NntpProviderPool $pool): int
    {
        $options = $this->resolveOptions();
        $limit = max(1, (int) $this->option('limit'));

        if ($options->dryRun) {
            $this->comment('Dry run: articles are probed, but no NZB and no repair state will be written.');
        }

        $candidates = ReleaseRepairCandidateQuery::batch($limit, $options->targetCompletion, $options->retryAfterHours);

        if ($candidates->isEmpty()) {
            $this->info('No releases are waiting for repair.');

            return self::SUCCESS;
        }

        $tally = array_fill_keys(array_column(ReleaseRepairOutcome::cases(), 'value'), 0);
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

            $tally[$result->outcome->value]++;
            $segmentsAdded += $result->segmentsAdded;
            $probes += $result->articlesProbed;
            $requeued += $result->requeuedForAdditionalProcessing ? 1 : 0;

            if ($this->output->isVerbose()) {
                $this->line(sprintf(
                    'Release %d: %s (%.2f%% -> %.2f%%) %s',
                    $release->id,
                    $result->outcome->label(),
                    $result->completionBefore,
                    $result->completionAfter,
                    $result->reason,
                ));
            }
        }

        $pool->quit();

        $this->table(
            ['Outcome', 'Releases'],
            array_map(
                static fn (ReleaseRepairOutcome $case): array => [$case->label(), $tally[$case->value]],
                ReleaseRepairOutcome::cases(),
            ),
        );

        $this->line(sprintf(
            'Worked %d release(s): %d segment(s) added, %d article(s) probed, %d re-queued for additional processing.',
            $candidates->count(),
            $segmentsAdded,
            $probes,
            $requeued,
        ));

        return self::SUCCESS;
    }

    private function resolveOptions(): ReleaseRepairOptions
    {
        return new ReleaseRepairOptions(
            targetCompletion: $this->floatOption('target') ?? ReleaseRepairOptions::targetFromSettings(),
            floorCompletion: $this->floatOption('floor') ?? ReleaseRepairOptions::REPAIR_FLOOR_COMPLETION,
            retryAfterHours: $this->intOption('retry-after-hours') ?? ReleaseRepairOptions::RETRY_AFTER_HOURS,
            statSamplePerFile: $this->intOption('stat-sample') ?? ReleaseRepairOptions::STAT_SAMPLE_PER_FILE,
            maxStatProbes: $this->intOption('max-probes') ?? ReleaseRepairOptions::MAX_STAT_PROBES,
            dryRun: (bool) $this->option('dry-run'),
        );
    }

    private function floatOption(string $name): ?float
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : (float) $value;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
