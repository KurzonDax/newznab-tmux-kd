<?php

declare(strict_types=1);

namespace App\Services\Nzb;

/**
 * Accumulates {@see CompletionSignals} one file at a time, for the callers that walk files
 * rather than aggregating them in SQL.
 */
final class CompletionTally
{
    private int $filesPresent = 0;

    private int $segmentsPresent = 0;

    private int $segmentsDeclared = 0;

    private int $filesDeclared = 0;

    private int $maxSegmentsPerFile = 0;

    private int $maxDeclaredPerFile = 0;

    private bool $declaredTotalsDiffer = false;

    /**
     * @param  int  $segments  Segments held for this file.
     * @param  int  $declaredSegments  The total this file's subject declares in its parens.
     * @param  int  $declaredFiles  The total this file's subject declares in its `[n/N]` file index.
     */
    public function addFile(int $segments, int $declaredSegments, int $declaredFiles = 0): void
    {
        $segments = max(0, $segments);
        $declaredSegments = max(0, $declaredSegments);

        if ($this->filesPresent > 0 && $declaredSegments !== $this->maxDeclaredPerFile) {
            $this->declaredTotalsDiffer = true;
        }

        $this->filesPresent++;
        $this->segmentsPresent += $segments;
        $this->segmentsDeclared += $declaredSegments;
        $this->maxSegmentsPerFile = max($this->maxSegmentsPerFile, $segments);
        $this->maxDeclaredPerFile = max($this->maxDeclaredPerFile, $declaredSegments);
        $this->filesDeclared = max($this->filesDeclared, max(0, $declaredFiles));
    }

    public function signals(): CompletionSignals
    {
        return new CompletionSignals(
            filesPresent: $this->filesPresent,
            segmentsPresent: $this->segmentsPresent,
            segmentsDeclared: $this->segmentsDeclared,
            filesDeclared: $this->filesDeclared,
            maxSegmentsPerFile: $this->maxSegmentsPerFile,
            distinctDeclaredTotals: $this->distinctDeclaredTotals(),
            maxDeclaredPerFile: $this->maxDeclaredPerFile,
        );
    }

    private function distinctDeclaredTotals(): int
    {
        if ($this->filesPresent === 0) {
            return 0;
        }

        // Only "all files agree" changes the measurement, so the exact count past one is noise.
        return $this->declaredTotalsDiffer ? 2 : 1;
    }
}
