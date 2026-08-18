<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MovieInfo;

final class MovieSearchIndexDocument
{
    /**
     * @return array{id: int, imdbid: string, tmdbid: int, traktid: int, title: string, year: string, genre: string, actors: string, director: string, rating: string, plot: string}
     */
    public static function fromMovie(MovieInfo $movie): array
    {
        return [
            'id' => (int) $movie->id,
            'imdbid' => (string) ($movie->imdbid ?? ''),
            'tmdbid' => (int) ($movie->tmdbid ?? 0),
            'traktid' => (int) ($movie->traktid ?? 0),
            'title' => (string) ($movie->title ?? ''),
            'year' => (string) ($movie->year ?? ''),
            'genre' => (string) ($movie->genre ?? ''),
            'actors' => (string) ($movie->actors ?? ''),
            'director' => (string) ($movie->director ?? ''),
            'rating' => (string) ($movie->rating ?? ''),
            'plot' => (string) ($movie->plot ?? ''),
        ];
    }
}
