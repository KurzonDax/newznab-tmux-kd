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
