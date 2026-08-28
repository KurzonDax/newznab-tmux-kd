<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

final readonly class AdditionalWorkPlan
{
    /**
     * @param  list<string>  $sampleMessageIds
     * @param  list<string>  $jpgMessageIds
     * @param  list<string>  $mediaInfoMessageIds
     * @param  list<string>  $mediaInfoTailMessageIds
     * @param  list<string>  $mediaInfoTailExpansionMessageIds
     * @param  list<string>  $mediaInfoExpansionMessageIds  Head segments after the fixed initial window, in posted order — the dynamic segment budget's top-up pool.
     * @param  int  $mediaInfoContiguousHeadSegments  Leading segments verified gap-free from segment number 1; when numbering is unavailable every segment counts (the gate can only skip provably pointless fetches).
     * @param  list<ArchiveCandidate>  $archiveCandidates
     * @param  list<UnknownPayloadCandidate>  $unknownPayloadCandidates
     * @param  list<string>  $unsupportedReasons
     */
    public function __construct(
        public array $sampleMessageIds = [],
        public array $jpgMessageIds = [],
        public array $mediaInfoMessageIds = [],
        public array $mediaInfoTailMessageIds = [],
        public array $mediaInfoTailExpansionMessageIds = [],
        public array $mediaInfoExpansionMessageIds = [],
        public int $mediaInfoContiguousHeadSegments = 0,
        public bool $mediaInfoTailContiguous = true,
        public int $mediaInfoFileSizeBytes = 0,
        public array $archiveCandidates = [],
        public array $unknownPayloadCandidates = [],
        public int $bookFileCount = 0,
        public bool $bookFlood = false,
        public int $duplicateMessageIdCount = 0,
        public array $unsupportedReasons = [],
    ) {}

    /**
     * @return list<string>
     */
    public function expandedMediaInfoTailMessageIds(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return array_slice(
            [...$this->mediaInfoTailExpansionMessageIds, ...$this->mediaInfoTailMessageIds],
            -$limit,
        );
    }

    public function hasCompressedFile(): bool
    {
        return $this->archiveCandidates !== [];
    }

    /**
     * @return list<ArchiveCandidate>
     */
    public function orderedArchiveCandidates(bool $reverse = false): array
    {
        return $reverse ? array_reverse($this->archiveCandidates) : $this->archiveCandidates;
    }

    /**
     * The archive parts posted after the given one, in posted order — the
     * volumes the compressed dynamic budget may extend into.
     *
     * @return list<ArchiveCandidate>
     */
    public function archivePartsAfter(ArchiveCandidate $anchor): array
    {
        return array_values(array_filter(
            $this->archiveCandidates,
            static fn (ArchiveCandidate $candidate): bool => $candidate->sourceIndex > $anchor->sourceIndex,
        ));
    }

    /**
     * Return a stable priority view without changing the legacy processing order.
     *
     * @return list<ArchiveCandidate>
     */
    public function prioritizedArchiveCandidates(): array
    {
        $firstVolumes = [];
        $remaining = [];

        foreach ($this->archiveCandidates as $candidate) {
            if ($candidate->likelyFirstVolume) {
                $firstVolumes[] = $candidate;
            } else {
                $remaining[] = $candidate;
            }
        }

        return [...$firstVolumes, ...$remaining];
    }
}
