<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use Illuminate\Support\Str;

/**
 * Honest accounting for one header storage run.
 *
 * A rolled-back chunk sends every article number it carried to part repair, but
 * that is a retry instruction, not mass data loss: the real failure is the one
 * or two headers that could not be placed. This report keeps both numbers so
 * the pane can say which is which.
 */
final readonly class HeaderStorageReport
{
    /**
     * @param  list<int|string>  $failedNumbers  Article numbers that must be refetched by part repair
     * @param  int  $unresolvedHeaders  Headers whose collection or binary id never resolved
     * @param  int  $rejectedHeaders  Headers that were unusable or whose part insert failed
     * @param  int  $rolledBackChunks  Chunks that exhausted their retries and rolled back
     * @param  int  $recoveredChunks  Chunks that failed an attempt and then stored on retry
     */
    public function __construct(
        public array $failedNumbers = [],
        public int $unresolvedHeaders = 0,
        public int $rejectedHeaders = 0,
        public int $rolledBackChunks = 0,
        public int $recoveredChunks = 0,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * Record a chunk that committed, optionally after one or more failed attempts.
     *
     * @param  list<int|string>  $failedNumbers  Parts the committed chunk still could not store
     */
    public function withStoredChunk(array $failedNumbers, bool $recovered): self
    {
        return new self(
            array_merge($this->failedNumbers, $failedNumbers),
            $this->unresolvedHeaders,
            $this->rejectedHeaders,
            $this->rolledBackChunks,
            $this->recoveredChunks + ($recovered ? 1 : 0),
        );
    }

    /**
     * Record a chunk that rolled back for good, with the reasons it did.
     *
     * @param  list<int|string>  $failedNumbers  Every article number the chunk carried
     */
    public function withRolledBackChunk(array $failedNumbers, int $unresolvedHeaders, int $rejectedHeaders): self
    {
        return new self(
            array_merge($this->failedNumbers, $failedNumbers),
            $this->unresolvedHeaders + $unresolvedHeaders,
            $this->rejectedHeaders + $rejectedHeaders,
            $this->rolledBackChunks + 1,
            $this->recoveredChunks,
        );
    }

    public function merge(self $other): self
    {
        return new self(
            array_merge($this->failedNumbers, $other->failedNumbers),
            $this->unresolvedHeaders + $other->unresolvedHeaders,
            $this->rejectedHeaders + $other->rejectedHeaders,
            $this->rolledBackChunks + $other->rolledBackChunks,
            $this->recoveredChunks + $other->recoveredChunks,
        );
    }

    /**
     * The same list with duplicate article numbers removed.
     *
     * @return list<int|string>
     */
    public function uniqueFailedNumbers(): array
    {
        return array_values(array_unique($this->failedNumbers));
    }

    public function hasNothingToReport(): bool
    {
        return $this->failedNumbers === [] && $this->recoveredChunks === 0;
    }

    /**
     * A one-line summary that separates headers that failed from articles queued for repair.
     */
    public function describe(): string
    {
        $queued = \count($this->uniqueFailedNumbers());

        $reasons = [];
        if ($this->unresolvedHeaders > 0) {
            $reasons[] = $this->unresolvedHeaders.Str::plural(' header', $this->unresolvedHeaders).' unresolved';
        }
        if ($this->rejectedHeaders > 0) {
            $reasons[] = $this->rejectedHeaders.Str::plural(' header', $this->rejectedHeaders).' rejected';
        }
        if ($this->rolledBackChunks > 0) {
            $reasons[] = $this->rolledBackChunks.Str::plural(' chunk', $this->rolledBackChunks).' rolled back';
        }

        $segments = [];
        if ($queued > 0) {
            $summary = $queued.Str::plural(' article', $queued).' queued for part repair';
            if ($reasons !== []) {
                $summary .= ' ('.implode(', ', $reasons).')';
            }
            $segments[] = $summary;
        }
        if ($this->recoveredChunks > 0) {
            $segments[] = $this->recoveredChunks.Str::plural(' chunk', $this->recoveredChunks).' stored on retry';
        }

        return $segments === [] ? 'No storage failures.' : implode('; ', $segments).'.';
    }
}
