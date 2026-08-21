<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReleaseRepairOutcome;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseRepair\MissingFileRescanOptions;
use App\Services\ReleaseRepair\MissingFileRescanService;
use App\Services\ReleaseRepair\RescanCandidateQuery;
use App\Services\ReleaseRepair\RescanRunBudget;
use App\Services\ReleaseRepair\RescanWindowResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recover files the header scan missed entirely, before the completion sweep may delete them.
 *
 * The companion to `releases:repair-completion`: that one rebuilds files whose message-IDs can be
 * derived from the segments already held, this one goes back to the group's headers for files
 * that have no segment at all. Bounded per invocation because XOVER over a window is expensive
 * and runs on the same provider connections live header scanning needs.
 */
class RescanMissingReleaseFiles extends Command
{
    protected $signature = 'releases:rescan-missing-files
        {--limit= : Releases to work on this invocation (default: the rescan_limit setting)}
        {--target= : Completion below which a release is a candidate (default: the completionpercent setting)}
        {--window-minutes= : How far either side of the known article span to look (default: the rescan_window_minutes setting)}
        {--max-articles= : Widest article range worth reading for one release (default: the rescan_max_articles_per_release setting)}
        {--max-articles-per-run= : Overview lines this invocation may read (default: the rescan_max_articles_per_run setting)}
        {--dry-run : Resolve declared counts and estimate windows, but fetch nothing and write nothing}';

    protected $description = 'Re-scan group headers for release files the scan missed entirely, and write them into the stored NZB';

    public function handle(NzbService $nzb): int
    {
        $options = $this->resolveOptions();
        $limit = MissingFileRescanOptions::limitFromSettings($this->intOption('limit'));

        if ($options->dryRun) {
            $this->comment('Dry run: declared counts and windows are resolved, but nothing is fetched and nothing is written.');
        }

        $candidates = RescanCandidateQuery::batch($limit, $options->targetCompletion, $options->retryAfterHours);

        if ($candidates->isEmpty()) {
            $this->info('No releases are waiting for a header re-scan.');

            return self::SUCCESS;
        }

        $nntp = new NNTPService;

        if (! $options->dryRun && $nntp->doConnect() !== true) {
            $this->error('Unable to connect to the primary provider.');

            return self::FAILURE;
        }

        $service = new MissingFileRescanService(
            $nzb,
            $nntp,
            new RescanWindowResolver($this->binariesOn($nntp)),
        );

        $budget = new RescanRunBudget($options->maxArticlesPerRun);

        $tally = array_fill_keys(array_column(ReleaseRepairOutcome::cases(), 'value'), 0);
        $notAttempted = 0;
        $filesRecovered = 0;
        $segmentsAdded = 0;
        $linesFetched = 0;

        foreach ($candidates as $release) {
            try {
                $result = $service->rescan($release, $options, $budget);
            } catch (\Throwable $e) {
                // One unrescannable release must not end the batch: leave its state untouched so
                // the next invocation picks it up again.
                Log::warning('Release header re-scan failed', ['release_id' => $release->id, 'error' => $e->getMessage()]);
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

            $filesRecovered += $result->filesRecovered;
            $segmentsAdded += $result->segmentsAdded;
            $linesFetched += $result->overviewLinesFetched;

            if ($this->output->isVerbose()) {
                $this->line(sprintf(
                    'Release %d: %s (%d/%d files, %.2f%% -> %.2f%%) %s',
                    $release->id,
                    $result->outcome?->label() ?? 'Not attempted',
                    $result->filesHeld,
                    $result->declaredFiles,
                    $result->completionBefore,
                    $result->completionAfter,
                    $result->reason,
                ));
            }
        }

        $nntp->doQuit();

        $rows = array_map(
            static fn (ReleaseRepairOutcome $case): array => [$case->label(), $tally[$case->value]],
            ReleaseRepairOutcome::cases(),
        );
        $rows[] = ['Not attempted (state untouched)', $notAttempted];

        $this->table(['Outcome', 'Releases'], $rows);

        $this->line(sprintf(
            'Visited %d release(s): %s overview line(s) fetched, %d file(s) and %d segment(s) recovered.',
            $candidates->count(),
            number_format($linesFetched),
            $filesRecovered,
            $segmentsAdded,
        ));

        if ($budget->isExhausted()) {
            $this->warn('The per-run overview budget was spent; the rest of the batch was left for the next invocation.');
        }

        return self::SUCCESS;
    }

    /**
     * The date-to-article bisection, pointed at the same connection the re-scan reads through.
     *
     * Only legacy releases -- those with no article anchors -- ever reach it. `echoCli` is the
     * one setting that reaches the search, and it is tied to `-v` here so a scheduled run does
     * not narrate every probe into the log.
     */
    private function binariesOn(NNTPService $nntp): BinariesService
    {
        $binaries = new BinariesService(config: new BinariesConfig(echoCli: $this->output->isVerbose()));
        $binaries->setNntp($nntp);

        return $binaries;
    }

    /**
     * Settings first, CLI flags on top: a flag that was not passed must not be read as a zero.
     */
    private function resolveOptions(): MissingFileRescanOptions
    {
        return MissingFileRescanOptions::fromSettings(
            targetCompletion: $this->floatOption('target'),
            windowMinutes: $this->intOption('window-minutes'),
            maxArticlesPerRelease: $this->intOption('max-articles'),
            maxArticlesPerRun: $this->intOption('max-articles-per-run'),
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
