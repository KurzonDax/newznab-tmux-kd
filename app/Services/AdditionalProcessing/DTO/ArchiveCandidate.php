<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

final readonly class ArchiveCandidate
{
    /**
     * @param  list<string>  $messageIds
     * @param  list<string>  $expansionMessageIds  Segments after the fixed initial window, in posted order — the compressed dynamic budget's top-up pool for this part.
     * @param  int  $contiguousHeadSegments  Leading segments provably gap-free from segment number 1; when numbering is unavailable every segment counts (the gate can only skip provably pointless fetches).
     * @param  bool  $tailContiguous  Whether the window an MP4 moov tail would read from this part is provably complete; see AdditionalWorkPlanner::tailIsContiguous().
     */
    public function __construct(
        public string $title,
        public array $messageIds,
        public bool $likelyFirstVolume,
        public int $sourceIndex,
        public array $expansionMessageIds = [],
        public int $contiguousHeadSegments = 0,
        public bool $tailContiguous = true,
    ) {}

    /**
     * Every segment of this part, in posted order.
     *
     * @return list<string>
     */
    public function segmentIds(): array
    {
        return array_values(array_unique([...$this->messageIds, ...$this->expansionMessageIds]));
    }

    public function isCompleteFromFirstSegment(): bool
    {
        return $this->contiguousHeadSegments === count($this->segmentIds());
    }
}
