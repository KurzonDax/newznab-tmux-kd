<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

/**
 * What the repair engine believes it can recover for one release.
 */
final readonly class ReleaseRepairPlan
{
    /**
     * @param  list<FileRepairPlan>  $files  Only files with something to synthesize.
     * @param  int  $filesWithoutTemplate  Files missing segments whose message-IDs are unguessable.
     * @param  int  $filesWithNoSegments  Files with no segment at all -- nothing to derive from.
     */
    public function __construct(
        public array $files,
        public int $filesWithoutTemplate,
        public int $filesWithNoSegments,
    ) {}

    public function synthesizedCount(): int
    {
        return array_sum(array_map(
            static fn (FileRepairPlan $file): int => $file->synthesizedCount(),
            $this->files,
        ));
    }

    public function hasWork(): bool
    {
        return $this->files !== [];
    }
}
