<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseResolution;

/** One episode row of the show page: what the season's releases declare, with the title and air date when known. */
final readonly class TvShowEpisode
{
    /** @param list<ReleaseResolution> $resolutions  the known ones present among the releases, in menu order */
    public function __construct(
        public int $number,
        public string $title,
        public string $aired,
        public int $releases,
        public array $resolutions,
        public string $smallest,
        public string $largest,
    ) {}

    /** E01, E00 for a special, E101. */
    public function label(): string
    {
        return sprintf('E%02d', $this->number);
    }

    /** "528 MB – 974 MB", or one size when every release is the same size. */
    public function sizes(): string
    {
        return $this->smallest === $this->largest ? $this->smallest : $this->smallest.' – '.$this->largest;
    }
}
