<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\State;

use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;

/**
 * Where one compressed dynamic-budget fetch stands. The moov phase and the
 * duration phase share a single instance, so no segment is requested or
 * appended twice and every fetched byte counts against the byte ceiling.
 */
final class CompressedTopUpFetch
{
    public int $partsUsed = 1;

    public int $currentVolume = 1;

    public string $currentVolumePath;

    /**
     * @param  array<string, true>  $fetchedMessageIds
     * @param  list<string>  $pendingSegments  Segments of the current volume still to fetch.
     * @param  list<ArchiveCandidate>  $nextParts  Parts not opened yet, in posted order.
     * @param  bool  $oldVolumeNumbers  Working volumes are named .rar/.r00/.r01 rather than .part001.rar.
     * @param  int  $anchorSegmentsHeld  Segments of the anchor part held so far.
     * @param  string  $fragmentPath  The longest fragment extraction has produced.
     */
    public function __construct(
        public readonly ArchiveCandidate $anchor,
        public readonly string $videoEntryName,
        public readonly int $perSegmentBytes,
        public array $fetchedMessageIds,
        public array $pendingSegments,
        public array $nextParts,
        public readonly string $firstVolumePath,
        public readonly bool $oldVolumeNumbers,
        public int $anchorSegmentsHeld,
        public int $fetchedBytes,
        public string $fragmentPath,
        public int $fragmentBytes,
    ) {
        $this->currentVolumePath = $firstVolumePath;
    }
}
