<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

final readonly class ArchiveCandidate
{
    /**
     * @param  list<string>  $messageIds
     * @param  list<string>  $expansionMessageIds  Segments after the fixed initial window, in posted order — the compressed dynamic budget's top-up pool for this part.
     * @param  int  $contiguousHeadSegments  Leading segments provably gap-free from segment number 1; when numbering is unavailable every segment counts (the gate can only skip provably pointless fetches).
     */
    public function __construct(
        public string $title,
        public array $messageIds,
        public bool $likelyFirstVolume,
        public int $sourceIndex,
        public array $expansionMessageIds = [],
        public int $contiguousHeadSegments = 0,
    ) {}
}
