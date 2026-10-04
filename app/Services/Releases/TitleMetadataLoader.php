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

    public function trailerUrl(string $trailer): ?string
    {
        if (preg_match('/\bsrc=["\']([^"\']+)["\']/i', $trailer, $match)) {
            $trailer = html_entity_decode($match[1]);
        }
        if ($this->webUrl($trailer) === null) {
            return null;
        }
        $host = strtolower((string) parse_url($trailer, PHP_URL_HOST));
        $path = (string) parse_url($trailer, PHP_URL_PATH);
        if ($host === 'v.traileraddict.com' && preg_match('/^\/[1-9][0-9]*\/?$/', $path)) {
            return 'https://v.traileraddict.com/'.trim($path, '/');
        }
        parse_str((string) parse_url($trailer, PHP_URL_QUERY), $query);
        $id = match ($host) {
            'youtu.be' => trim($path, '/'),
            'youtube.com', 'www.youtube.com', 'www.youtube-nocookie.com' => str_starts_with($path, '/embed/')
                ? substr($path, 7) : ($query['v'] ?? ''),
            default => '',
        };

        return is_string($id) && preg_match('/^[a-zA-Z0-9_-]{11}$/', $id)
            ? 'https://www.youtube-nocookie.com/embed/'.$id : null;
    }

    private function webUrl(string $value): ?string
    {
        return filter_var($value, FILTER_VALIDATE_URL) && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true) ? $value : null;
    }
}
