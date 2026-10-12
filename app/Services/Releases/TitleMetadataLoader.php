<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\BrowseRoot;

final class TitleMetadataLoader
{
    /** @return array{table:string, key:string, releaseKey:string, art:string} */
    public function source(BrowseRoot $root): array
    {
        [$table, $key, $releaseKey, $art] = match ($root) {
            BrowseRoot::Movies => ['movieinfo', 'imdbid', 'imdbid', 'movies'],
            BrowseRoot::Tv => ['videos', 'id', 'videos_id', 'tvshows'],
            BrowseRoot::Audio => ['musicinfo', 'id', 'musicinfo_id', 'music'],
            BrowseRoot::Console => ['consoleinfo', 'id', 'consoleinfo_id', 'console'],
            BrowseRoot::Games => ['gamesinfo', 'id', 'gamesinfo_id', 'games'],
            BrowseRoot::Books => ['bookinfo', 'id', 'bookinfo_id', 'book'],
            default => abort(404),
        };

        return compact('table', 'key', 'releaseKey', 'art');
    }
}
