<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\DTO;

use App\Services\AudioProcessing\Enums\AudioSourceKind;

/**
 * The file (or archive set) chosen out of an NZB to build a preview from.
 */
final readonly class AudioSource
{
    /**
     * @param  string  $extension  Upper-cased, without the dot. Empty for an archive,
     *                             whose contents are only known once it is opened.
     * @param  list<list<string>>  $parts  Message ids grouped the way they are fetched:
     *                                     one entry holding every segment for a bare
     *                                     file, one entry per archive volume otherwise.
     */
    public function __construct(
        public AudioSourceKind $kind,
        public string $title,
        public string $extension,
        public array $parts,
    ) {}

    /**
     * @return list<string>
     */
    public function firstPartSegments(): array
    {
        return $this->parts[0] ?? [];
    }
}
