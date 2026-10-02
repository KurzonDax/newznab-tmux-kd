<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\PhantomTrailingFile;
use App\Services\ReleaseRepair\NzbRepairDocument;
use App\Services\ReleaseRepair\RecoveryLease;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Measure `completion` for releases whose stored value predates how it is measured now.
 *
 * Three populations, one per run:
 *
 * - By default, releases still carrying the `0` sentinel. They were stored before completion was
 *   recorded at all, and `0` exempts them from the completion sweep.
 * - With `--understated`, releases measured below {@see self::UNDERSTATED_CEILING}%. The old
 *   arithmetic summed each file's declared total, which for the obfuscated single-segment style
 *   (one segment per file, the parens repeating a collection-wide total) invents a denominator
 *   hundreds of times too large -- 220 of 240 files was stored as 0.42%. See {@see CompletionSignals}.
 * - With `--phantom-trailing`, releases measured below 100% that declared one file more than they
 *   hold, or never recorded a declared count. Where the stored NZB shows a
 *   {@see PhantomTrailingFile}, the release is re-measured against the files held and stores that
 *   count, except a reconciled posting, which keeps the declared count late collections match on.
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
        {--phantom-trailing : Re-measure rows that declared one file they never posted instead of the never-measured ones}
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

        if ((bool) $this->option('phantom-trailing')) {
            if ($understated) {
                $this->error('--phantom-trailing and --understated select different populations; run them separately.');

                return self::FAILURE;
            }

            return $this->correctPhantomTrailingFiles($nzb, $dryRun, $limit, $chunk);
        }

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
     * Re-measure releases whose stored NZB shows a {@see PhantomTrailingFile}.
     *
     * Each write lands only while the row still holds what was read and no recovery lease is
     * live: repair, re-scan and duplicate absorption rewrite these columns under that lease, and a
     * value measured from an NZB they have since replaced must not overwrite theirs.
     */
    private function correctPhantomTrailingFiles(NzbService $nzb, bool $dryRun, int $limit, int $chunk): int
    {
        $matched = 0;
        $unmatched = 0;
        $skipped = 0;
        $missingNzb = 0;
        $seen = 0;
        $hasReconciledPostings = Schema::hasTable('reconciled_postings');

        if ($dryRun) {
            $this->comment('Dry run: nothing will be written.');
        }

        $this->comment('Re-measuring releases that declared one file they never posted.');

        Release::query()
            ->where('nzbstatus', '=', NzbService::NZB_ADDED)
            ->where('completion', '>', 0)
            ->where('completion', '<', 100)
            ->where(static function (Builder $query): void {
                $query->whereNull('declaredfiles')
                    ->orWhereRaw('declaredfiles = totalpart + 1');
            })
            ->select(['id', 'guid', 'totalpart', 'declaredfiles', 'completion'])
            ->orderBy('id')
            ->chunkById($chunk, function ($releases) use (
                $nzb, $dryRun, $limit, $hasReconciledPostings, &$matched, &$unmatched, &$skipped, &$missingNzb, &$seen
            ): bool {
                foreach ($releases as $release) {
                    $seen++;

                    $contents = $nzb->readNzbContents((string) $release->guid);

                    if ($contents === false) {
                        $missingNzb++;
                    } else {
                        $document = NzbRepairDocument::load($contents);
                        $held = $document === null ? null : PhantomTrailingFile::heldCount($document->subjects());

                        if ($document === null || $held === null) {
                            $unmatched++;
                        } elseif ($this->writePhantomTrailingCorrection($release, $document, $held, $dryRun, $hasReconciledPostings)) {
                            $matched++;
                        } else {
                            $skipped++;
                        }
                    }

                    if ($limit > 0 && $seen >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        $this->line('');
        $this->line(sprintf(
            'Examined %d release(s): %d matched, %d unmatched, %d skipped (changed since read, or held by recovery), %d with no NZB on disk.',
            $seen,
            $matched,
            $unmatched,
            $skipped,
            $missingNzb,
        ));

        if (! $dryRun && $matched > 0) {
            Log::info('Backfilled releases.completion', [
                'population' => 'phantom trailing file',
                'examined' => $seen,
                'matched' => $matched,
                'unmatched' => $unmatched,
                'skipped' => $skipped,
                'missing_nzb' => $missingNzb,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Store the re-measured completion, and the held count unless this is a reconciled posting.
     *
     * @return bool False when the row changed since it was read or a recovery lease holds it.
     */
    private function writePhantomTrailingCorrection(Release $release, NzbRepairDocument $document, int $held, bool $dryRun, bool $hasReconciledPostings): bool
    {
        $unchangedRow = RecoveryLease::applyAvailable(
            Release::query()
                ->whereKey($release->id)
                ->where('totalpart', '=', $release->totalpart)
                ->where('completion', '=', $release->completion)
                ->when(
                    $release->declaredfiles === null,
                    static fn (Builder $query) => $query->whereNull('declaredfiles'),
                    static fn (Builder $query) => $query->where('declaredfiles', '=', $release->declaredfiles),
                )
        );

        if ($dryRun) {
            return $unchangedRow->exists();
        }

        $values = ['completion' => $document->measure()->percentage()];

        // Late collections merge into a reconciled posting only while its declared count matches theirs.
        if (! $hasReconciledPostings || ! DB::table('reconciled_postings')->where('release_id', $release->id)->exists()) {
            $values['declaredfiles'] = $held;
        }

        if ($unchangedRow->update($values) !== 1) {
            return false;
        }

        Release::syncSearchIndexAfterCommit((int) $release->id);

        return true;
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
