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
     * @param  int  $declaredPerFile  The largest per-file total declared.
     */
    public function __construct(
        public int $filesPresent = 0,
        public int $segmentsPresent = 0,
        public int $segmentsDeclared = 0,
        public int $filesDeclared = 0,
        public int $maxSegmentsPerFile = 0,
        public int $distinctDeclaredTotals = 0,
        public int $declaredPerFile = 0,
    ) {}

    /**
     * Completion as a 0-100 percentage, `0` meaning "never measured".
     */
    public function percentage(): float
    {
        if ($this->isSingleSegmentStyle()) {
            return ReleaseCompletion::percentage($this->filesPresent, $this->fileDenominator());
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
        return $this->isSingleSegmentStyle()
            ? $this->fileDenominator() > 0
            : $this->segmentsDeclared > 0;
    }

    /**
     * Is every file a lone segment whose parens repeat one collection-wide total?
     *
     * A post of genuinely single-part files declares `(1/1)` and is measured normally; what marks
     * the obfuscated style is that the repeated total is far larger than the one segment present.
     */
    public function isSingleSegmentStyle(): bool
    {
        return $this->filesPresent > 1
            && $this->maxSegmentsPerFile === 1
            && $this->distinctDeclaredTotals === 1
            && $this->declaredPerFile > 1;
    }

    /**
     * Files to measure against in the obfuscated style.
     *
     * The file index (`[n/N]`, stored as `collections.totalfiles`) is the authority: it counts
     * files, which is what we are counting. The repeated parens total stands in only when no file
     * index survived -- in the observed posts the two agree, and where they do not the index is
     * the number that was actually about files.
     */
    private function fileDenominator(): int
    {
        return $this->filesDeclared > 0 ? $this->filesDeclared : $this->declaredPerFile;
    }
}
