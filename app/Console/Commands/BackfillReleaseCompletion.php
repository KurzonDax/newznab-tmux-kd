<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseRepair\NzbRepairDocument;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Measure `completion` for releases whose stored value predates how it is measured now.
 *
 * Two populations, one per run:
 *
 * - By default, releases still carrying the `0` sentinel. They were stored before completion was
 *   recorded at all, and `0` exempts them from the completion sweep.
 * - With `--understated`, releases measured below {@see self::UNDERSTATED_CEILING}%. The old
 *   arithmetic summed each file's declared total, which for the obfuscated single-segment style
 *   (one segment per file, the parens repeating a collection-wide total) invents a denominator
 *   hundreds of times too large -- 220 of 240 files was stored as 0.42%. See {@see CompletionSignals}.
 *
 * Rerunnable either way: the measurement is a pure function of the NZB on disk. Purely local too,
 * no NNTP and no network. A release whose subjects declare no totals has no denominator to measure
 * against and keeps whatever it already had rather than being recorded as 0%.
 */
class BackfillReleaseCompletion extends Command
{
    /** Bands the dry run reports, as lower bounds. */
    private const array HISTOGRAM_BANDS = [0, 10, 25, 50, 75, 90, 95, 99];

    /** Below this, a stored measurement is suspected of being the understated per-file sum. */
    private const float UNDERSTATED_CEILING = 50.0;

    protected $signature = 'releases:backfill-completion
        {--understated : Re-derive rows already measured below 50% instead of the never-measured ones}
        {--limit=0 : Stop after this many releases (0 = every release in the population)}
        {--chunk=500 : Releases to load per database round trip}
        {--dry-run : Report the completion bands that would be written, and write nothing}';

    protected $description = 'Measure releases.completion from stored NZBs for releases whose value predates the current arithmetic';

    public function handle(NzbService $nzb): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $understated = (bool) $this->option('understated');
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

        $this->comment($understated
            ? sprintf('Re-deriving releases already measured below %s%%.', self::UNDERSTATED_CEILING)
            : 'Measuring releases that have never been measured.');

        $this->candidates($understated)
            ->where('nzbstatus', '=', NzbService::NZB_ADDED)
            ->select(['id', 'guid'])
            ->orderBy('id')
            ->chunkById($chunk, function ($releases) use (
                $nzb, $dryRun, $limit, &$bands, &$measured, &$unmeasurable, &$missingNzb, &$seen
            ): bool {
                foreach ($releases as $release) {
                    $seen++;

                    $contents = $nzb->readNzbContents((string) $release->guid);

                    if ($contents === false) {
                        $missingNzb++;

                        continue;
                    }

                    $measurement = NzbRepairDocument::load($contents)?->measure();

                    if ($measurement === null || ! $measurement->isMeasurable()) {
                        // Unparseable, or no subject declared a total so there is no denominator.
                        // Either way the release keeps what it already had -- for the default pass
                        // that is the `0` sentinel, which exempts it from the sweep rather than
                        // recording it as 0% complete.
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
            'Examined %d release(s): %d measured, %d without declared totals (left as they were), %d with no NZB on disk.',
            $seen,
            $measured,
            $unmeasurable,
            $missingNzb,
        ));

        if (! $dryRun && $measured > 0) {
            Log::info('Backfilled releases.completion', [
                'population' => $understated ? 'understated' : 'never measured',
                'examined' => $seen,
                'measured' => $measured,
                'unmeasurable' => $unmeasurable,
                'missing_nzb' => $missingNzb,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * The releases this run is responsible for.
     *
     * The two populations are deliberately disjoint: `0` means "never measured" and is not a
     * small percentage, so an understated run must not sweep it up and record it as a real value.
     *
     * @return Builder<Release>
     */
    private function candidates(bool $understated): Builder
    {
        if (! $understated) {
            return Release::query()->where('completion', '=', 0);
        }

        return Release::query()
            ->where('completion', '>', 0)
            ->where('completion', '<', self::UNDERSTATED_CEILING);
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
