<?php

declare(strict_types=1);

namespace App\Data;

/**
 * One tile on the Films wall (docs/proposals/movies-redesign/SPEC.md 5A.3): the poster (or the name
 * card with the title and year), the title, `Year · two genres`, `8.5 · PG-13` and `N releases`.
 */
final readonly class MovieFilmTile
{
    /**
     * @param  list<string>  $genres  at most two, a genre the Genre filter matched first
     * @param  string  $line2  the score line: `8.5 · PG-13`, `Too few votes · R`, `7`
     * @param  string  $line3  the viewer's visible releases: `3 releases`
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $url,
        public ?string $poster,
        public string $year,
        public array $genres,
        public string $line2,
        public string $line3,
    ) {}
}
