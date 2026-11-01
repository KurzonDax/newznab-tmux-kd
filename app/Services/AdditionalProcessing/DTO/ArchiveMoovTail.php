<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

final readonly class ArchiveMoovTail
{
    /**
     * @param  string|null  $splicedData  The fragment with the moov spliced on; null when no splice happened.
     * @param  string|null  $moov  The bare moov atom, for re-splicing after the fragment is re-extracted; null when no splice happened.
     * @param  list<string>  $fetchedMessageIds  Segments fetched for the tail, whether or not a splice followed.
     */
    public function __construct(
        public ?string $splicedData = null,
        public ?string $moov = null,
        public array $fetchedMessageIds = [],
    ) {}
}
