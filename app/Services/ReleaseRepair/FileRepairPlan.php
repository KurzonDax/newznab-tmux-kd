<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

/**
 * What can be recovered for one file inside an NZB.
 */
final readonly class FileRepairPlan
{
    /**
     * @param  int  $fileIndex  Position of the `<file>` element in document order.
     * @param  array<int, string>  $synthesized  Segment number => derived message-ID.
     */
    public function __construct(
        public int $fileIndex,
        public string $subject,
        public int $declaredTotal,
        public int $presentCount,
        public array $synthesized,
    ) {}

    public function synthesizedCount(): int
    {
        return count($this->synthesized);
    }

    /**
     * Segment numbers to spot-check, spread across the missing range.
     *
     * The ends are the most informative samples: a template that is wrong about padding or about
     * which digit run varies breaks at the extremes first, where the number's width changes.
     *
     * Deterministic, which is what makes the second pass cheap: it re-probes exactly the IDs the
     * first pass could not confirm, and a file is accepted on that same sample or not at all.
     *
     * @return list<int>
     */
    public function verificationSample(int $sampleSize): array
    {
        $numbers = array_keys($this->synthesized);
        $count = count($numbers);

        if ($sampleSize <= 0 || $count === 0) {
            return [];
        }

        if ($count <= $sampleSize) {
            return $numbers;
        }

        if ($sampleSize === 1) {
            return [$numbers[0]];
        }

        // Evenly spaced, always including both ends.
        $sample = [];

        for ($i = 0; $i < $sampleSize; $i++) {
            $index = (int) round(($i * ($count - 1)) / ($sampleSize - 1));
            $sample[$numbers[$index]] = true;
        }

        return array_keys($sample);
    }
}
