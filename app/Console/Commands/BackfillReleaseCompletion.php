<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\Nzb\NzbCompletionMeasurer;
use App\Services\Nzb\NzbService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Measure `completion` for releases created before it was recorded.
 *
 * `completion` has been written at NZB-creation time since #144, but the releases that predate
 * that carry the `0` sentinel and are exempt from the completion sweep. This walks them, reads
 * the NZB already on disk, and measures it with the same arithmetic creation-time uses.
 *
 * Purely local: no NNTP, no network. A release whose subjects declare no part totals has no
 * denominator to measure against and keeps the `0` sentinel rather than being recorded as 0%.
 */
class BackfillReleaseCompletion extends Command
{
    /** Bands the dry run reports, as lower bounds. */
    private const array HISTOGRAM_BANDS = [0, 10, 25, 50, 75, 90, 95, 99];

    protected $signature = 'releases:backfill-completion
        {--limit=0 : Stop after this many releases (0 = every unmeasured release)}
        {--chunk=500 : Releases to load per database round trip}
        {--dry-run : Report the completion bands that would be written, and write nothing}';

    protected $description = 'Measure releases.completion for releases stored before completion was recorded';

    public function handle(NzbService $nzb, NzbCompletionMeasurer $measurer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(1, (int) $this->option('chunk'));

        $bands = array_fill_keys(self::HISTOGRAM_BANDS, 0);
        $measured = 0;
        $unmeasurable = 0;
        $missingNzb = 0;
        $seen = 0;

        if ($dryRun) {
            $this->comment('Dry run: nothing will be written.');
        }

        Release::query()
            ->where('completion', '=', 0)
            ->where('nzbstatus', '=', NzbService::NZB_ADDED)
            ->select(['id', 'guid'])
            ->orderBy('id')
            ->chunkById($chunk, function ($releases) use (
                $nzb, $measurer, $dryRun, $limit, &$bands, &$measured, &$unmeasurable, &$missingNzb, &$seen
            ): bool {
                foreach ($releases as $release) {
                    $seen++;

                    $contents = $nzb->readNzbContents((string) $release->guid);

                    if ($contents === false) {
                        $missingNzb++;

                        continue;
                    }

                    $measurement = $measurer->measure($contents);

                    if (! $measurement->isMeasurable()) {
                        // No subject declared a part total, so there is no denominator. The
                        // release keeps the `0` sentinel and stays exempt from the sweep.
                        $unmeasurable++;

                        continue;
                    }

                    $percentage = $measurement->percentage();
                    $bands[$this->bandFor($percentage)]++;
                    $measured++;

                    if (! $dryRun) {
                        Release::query()->where('id', $release->id)->update(['completion' => $percentage]);
                    }

                    if ($limit > 0 && $seen >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        $this->reportBands($bands, $measured);

        $this->line('');
        $this->line(sprintf(
            'Examined %d release(s): %d measured, %d without declared part totals (left at 0), %d with no NZB on disk.',
            $seen,
            $measured,
            $unmeasurable,
            $missingNzb,
        ));

        if (! $dryRun && $measured > 0) {
            Log::info('Backfilled releases.completion', [
                'examined' => $seen,
                'measured' => $measured,
                'unmeasurable' => $unmeasurable,
                'missing_nzb' => $missingNzb,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * The lower bound of the band this percentage falls in.
     */
    private function bandFor(float $percentage): int
    {
        $band = self::HISTOGRAM_BANDS[0];

        foreach (self::HISTOGRAM_BANDS as $lowerBound) {
            if ($percentage >= $lowerBound) {
                $band = $lowerBound;
            }
        }

        return $band;
    }

    /**
     * @param  array<int, int>  $bands
     */
    private function reportBands(array $bands, int $measured): void
    {
        $bounds = self::HISTOGRAM_BANDS;
        $rows = [];

        foreach ($bounds as $index => $lowerBound) {
            $upperBound = $bounds[$index + 1] ?? null;
            $count = $bands[$lowerBound];

            $rows[] = [
                $upperBound === null ? $lowerBound.'% - 100%' : $lowerBound.'% - <'.$upperBound.'%',
                $count,
                $measured > 0 ? sprintf('%.1f%%', ($count / $measured) * 100) : '-',
            ];
        }

        $this->table(['Completion band', 'Releases', 'Share'], $rows);
    }
}
