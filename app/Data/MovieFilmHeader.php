<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReleaseResolution;

/** The film page's header (docs/proposals/movies-redesign/SPEC.md 5B.1), ready to render. */
final readonly class MovieFilmHeader
{
    /**
     * @param  string  $imdbId  the Follow key (`movies:<imdbid>`) and the IMDb link's id
     * @param  string  $year  four digits, or '' when unknown
     * @param  int  $releases  the film's Movies releases the viewer may see
     * @param  string  $latest  when the newest of them was posted ("2 hr ago", "Sep 16, 2026"); '' without releases
     * @param  ReleaseResolution|null  $best  the best known resolution among them; null when none is known
     * @param  array<int, string>  $genres  genres.id => title, in TMDB's order
     * @param  list<string>  $tags  the plain tags: `Score 8.4` or `Too few votes`, the MPAA rating, the language
     * @param  array<int, string>  $directors  people.id => name, in order
     * @param  array<int, string>  $cast  people.id => name, the first STARRING_LIMIT in order
     * @param  array<string, string>  $links  label => outside URL: IMDb always, TMDB and Trakt when known
     */
    public function __construct(
        public int $id,
        public string $imdbId,
        public string $title,
        public string $year,
        public ?string $poster,
        public int $releases,
        public string $latest,
        public ?ReleaseResolution $best,
        public string $plot,
        public array $genres,
        public array $tags,
        public array $directors,
        public array $cast,
        public array $links,
    ) {}

    /**
     * The grey line before the best resolution's chip: `2010`, `23 releases`, `latest Sep 16, 2026`,
     * each when known.
     *
     * @return list<string>
     */
    public function meta(): array
    {
        return array_values(array_filter([
            $this->year,
            number_format($this->releases).' '.($this->releases === 1 ? 'release' : 'releases'),
            $this->latest === '' ? '' : 'latest '.$this->latest,
        ], static fn (string $part): bool => $part !== ''));
    }

    /** The year as a number for Similar films' year term; null when unknown. */
    public function yearNumber(): ?int
    {
        return $this->year === '' ? null : (int) $this->year;
    }
}
