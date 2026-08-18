<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Facades\Search;
use App\Models\MovieInfo;
use App\Support\MovieSearchIndexDocument;
use Illuminate\Support\Facades\Log;

final class MovieSearchIndexSync
{
    public static function sync(MovieInfo $movie): void
    {
        try {
            Search::insertMovie(MovieSearchIndexDocument::fromMovie($movie));
        } catch (\Throwable $e) {
            Log::error('Failed to sync movie to search index', [
                'movie_id' => $movie->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
