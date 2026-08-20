<?php

declare(strict_types=1);

namespace App\Services\Nzb;

/**
 * What a release's files say about how much of it we hold.
 *
 * Two posting styles have to be told apart, because they mean different things by the
 * `(n/N)` in a subject:
 *
 * - The normal style splits a file across N segments, so N is a per-file segment count and
 *   completion is segments held over segments declared.
 * - The obfuscated style posts every file as a single segment and repeats one collection-wide
 *   total in every subject's parens (`[37/240] - "hash" yEnc (1/240)`). Summing that total once
 *   per file invents a denominator hundreds of times too large: 220 of 240 files -- 92% of the
 *   post -- reads as 0.42%. There, completion is files held over files declared.
 *
 * Telling them apart takes corroboration, not shape alone. A normal post we hold one segment of
 * per file has the same shape as the obfuscated style -- equal-sized rar volumes declare
 * identical totals -- and measuring it in files would report a near-empty release as nearly
 * complete. So the parens total must agree with the `[n/N]` file index before it is read as a
 * file count. Where the two exist and disagree, nothing here is trustworthy and the release keeps
 * the `0` "never measured" sentinel: an unknown release is exempt from the completion sweep,
 * where a flattering guess would be indistinguishable from a genuinely complete one.
 */
final readonly class CompletionSignals
{
    /**
     * @param  int  $filesPresent  Files we hold (binaries, or `<file>` elements).
     * @param  int  $segmentsPresent  Segments we hold across all files.
     * @param  int  $segmentsDeclared  Sum of the per-file totals the subjects' parens declare.
     * @param  int  $filesDeclared  Files the `[n/N]` file index declares; `0` when none survived.
     * @param  int  $maxSegmentsPerFile  Most segments held by any one file.
     * @param  int  $distinctDeclaredTotals  How many different per-file totals the subjects declare.
     * @param  int  $maxDeclaredPerFile  The largest per-file total declared.
     */
    public function __construct(
        public int $filesPresent = 0,
        public int $segmentsPresent = 0,
        public int $segmentsDeclared = 0,
        public int $filesDeclared = 0,
        public int $maxSegmentsPerFile = 0,
        public int $distinctDeclaredTotals = 0,
        public int $maxDeclaredPerFile = 0,
    ) {}

    /**
     * Completion as a 0-100 percentage, `0` meaning "never measured".
     */
    public function percentage(): float
    {
        if ($this->hasIrreconcilableSignals()) {
            return 0.0;
        }

        if ($this->isSingleSegmentStyle()) {
            return ReleaseCompletion::percentage($this->filesPresent, $this->filesDeclared);
        }

        return ReleaseCompletion::percentage($this->segmentsPresent, $this->segmentsDeclared);
    }

    /**
     * Is there a denominator to measure against?
     *
     * Without one the release keeps the `0` sentinel rather than being recorded as 0% complete.
     */
    public function isMeasurable(): bool
    {
        if ($this->hasIrreconcilableSignals()) {
            return false;
        }

        return $this->isSingleSegmentStyle()
            ? $this->filesDeclared > 0
            : $this->segmentsDeclared > 0;
    }

    /**
     * Is every file a lone segment whose parens repeat the collection-wide file count?
     *
     * The shape alone is not enough (see {@see self::hasSingleSegmentShape()}); the `[n/N]` file
     * index has to agree that the repeated total counts files. In the observed posts it does --
     * `[37/240]` against `(1/240)`, `[10/1083]` against `(1/1083)`.
     */
    public function isSingleSegmentStyle(): bool
    {
        return $this->hasSingleSegmentShape() && $this->filesDeclared === $this->maxDeclaredPerFile;
    }

    /**
     * Do the two totals contradict each other?
     *
     * Chiefly a collection that timed out: `ReleaseProcessingService` rewrites `totalfiles` to the
     * files actually present once a collection goes stale, so the file index comes back equal to
     * the numerator and would measure a release that never finished arriving as 100% complete.
     */
    public function hasIrreconcilableSignals(): bool
    {
        return $this->hasSingleSegmentShape()
            && $this->filesDeclared > 0
            && $this->filesDeclared !== $this->maxDeclaredPerFile;
    }

    /**
     * The obfuscated style's shape: more than one file, none holding more than a single segment,
     * and every subject declaring the same total -- a total too large to be this file's own.
     *
     * A post of genuinely single-part files declares `(1/1)`, so the `> 1` keeps it out.
     */
    private function hasSingleSegmentShape(): bool
    {
        return $this->filesPresent > 1
            && $this->maxSegmentsPerFile === 1
            && $this->distinctDeclaredTotals === 1
            && $this->maxDeclaredPerFile > 1;
    }
}
