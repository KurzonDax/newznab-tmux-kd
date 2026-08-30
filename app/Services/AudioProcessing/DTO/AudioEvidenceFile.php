<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\DTO;

/**
 * One filename observed in the stored NZB without downloading another byte.
 */
final readonly class AudioEvidenceFile
{
    /**
     * @param  array<string, mixed>  $facts
     */
    public function __construct(
        public int $ordinal,
        public string $filename,
        public int $segmentCount,
        public string $kind,
        public array $facts = [],
    ) {}

    /**
     * @return array{source: string, ordinal: int, filename: string, segment_count: int, kind: string, facts: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'source' => 'nzb',
            'ordinal' => $this->ordinal,
            'filename' => $this->filename,
            'segment_count' => $this->segmentCount,
            'kind' => $this->kind,
            'facts' => $this->facts,
        ];
    }
}
