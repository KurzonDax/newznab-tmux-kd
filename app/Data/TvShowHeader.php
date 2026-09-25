<?php

declare(strict_types=1);

namespace App\Data;

/** The show page's header: poster, title, `Network · year · N seasons on site · N releases`, summary, tags and Starring. */
final readonly class TvShowHeader
{
    /**
     * @param  array<int, string>  $genres  genres.id => title, alphabetical
     * @param  list<string>  $tags  language, US rating and status, each only when known
     * @param  array<int, string>  $starring  people.id => name, in TMDB's order
     */
    public function __construct(
        public int $id,
        public string $title,
        public ?string $poster,
        public string $network,
        public ?int $year,
        public int $releases,
        public string $summary,
        public array $genres,
        public array $tags,
        public array $starring,
    ) {}

    /** Network · year · N seasons on site · N releases, leaving out what is unknown or zero. */
    public function meta(int $seasons): string
    {
        return implode(' · ', array_filter([
            $this->network,
            $this->year === null ? '' : (string) $this->year,
            $seasons === 0 ? '' : $seasons.' '.($seasons === 1 ? 'season' : 'seasons').' on site',
            number_format($this->releases).' '.($this->releases === 1 ? 'release' : 'releases'),
        ], static fn (string $part): bool => $part !== ''));
    }
}
