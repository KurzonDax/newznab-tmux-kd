<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Data\ReleaseEntityData;
use App\Data\TitleOverviewData;
use App\Enums\BrowseRoot;
use Illuminate\Support\Facades\DB;

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

    public function load(BrowseRoot $root, string $id): TitleOverviewData
    {
        $source = $this->source($root);
        $record = DB::table($source['table'])->where($source['key'], $id)->first();
        abort_if($record === null, 404);
        $info = $root === BrowseRoot::Tv ? DB::table('tv_info')->where('videos_id', $id)->first() : null;
        $genre = in_array($root, [BrowseRoot::Audio, BrowseRoot::Console, BrowseRoot::Games], true)
            ? DB::table('genres')->where('id', $record->genres_id ?? 0)->value('title') : ($record->genre ?? null);
        $tracks = $root === BrowseRoot::Audio ? $this->tracks((string) ($record->tracks ?? '')) : [];
        $date = collect([$record->year ?? null, $record->started ?? null, $record->releasedate ?? null, $record->publishdate ?? null])
            ->first(static fn ($value): bool => trim((string) $value) !== '' && (int) $value > 0);
        $year = substr((string) $date, 0, 4);
        $metadata = match ($root) {
            BrowseRoot::Movies => ['Year' => $year, 'Rating' => $record->rating ?? null, 'Genre' => $genre,
                'Runtime' => empty($record->runtime) ? null : $record->runtime.' min', 'Director' => $record->director ?? null, 'Cast' => $record->actors ?? null],
            BrowseRoot::Tv => ['Network' => $info->publisher ?? null, 'First aired' => $record->started ?? null],
            BrowseRoot::Audio => ['Artist' => $record->artist ?? null, 'Year' => $year, 'Label' => $record->publisher ?? null,
                'Genre' => $genre, 'Tracks' => $tracks === [] ? ($record->tracks ?? null) : count($tracks)],
            BrowseRoot::Console, BrowseRoot::Games => ['Platform' => $root === BrowseRoot::Games ? 'PC' : ($record->platform ?? null),
                'Publisher' => $record->publisher ?? null, 'Genre' => $genre, 'Released' => $record->releasedate ?? null, 'ESRB' => $record->esrb ?? null],
            BrowseRoot::Books => ['Author' => $record->author ?? null, 'Publisher' => $record->publisher ?? null,
                'Published' => $record->publishdate ?? null, 'Pages' => $record->pages ?? null, 'ISBN' => $record->isbn ?? null, 'Genre' => $genre],
            default => [],
        };
        $metadata = array_filter(array_map(static fn ($value): string => trim(strip_tags((string) $value)), $metadata),
            static fn (string $value): bool => $value !== '' && $value !== '0' && ! str_starts_with($value, '0000-'));
        $overview = match ($root) {
            BrowseRoot::Movies => $record->plot ?? '', BrowseRoot::Tv => $info->summary ?? '',
            BrowseRoot::Books => $record->overview ?? '', default => '',
        };

        return new TitleOverviewData(
            root: $root,
            entity: new ReleaseEntityData($root->value, $id, (string) $record->title, $year === '' ? null : $year,
                getImageAssetUrl($source['art'], $root === BrowseRoot::Movies ? $id.'-cover' : $id)),
            subtitle: $root === BrowseRoot::Audio ? (string) ($record->artist ?? '') : ($root === BrowseRoot::Tv ? '' : $year),
            metadata: $metadata, links: $this->links($root, $id, $record),
            overview: trim(html_entity_decode(strip_tags((string) $overview))), tracks: $tracks,
            trailerUrl: $root === BrowseRoot::Movies ? $this->trailerUrl((string) ($record->trailer ?? '')) : null,
        );
    }

    /** @return list<string> */
    private function tracks(string $value): array
    {
        if (is_numeric(trim($value))) {
            return [];
        }
        $text = html_entity_decode(strip_tags(preg_replace('/<br\s*\/?\s*>/i', "\n", $value) ?? $value));

        return array_values(array_filter(array_map(static fn (string $track): string => trim(preg_replace('/^\s*\d+[.)]\s*/', '', $track) ?? $track),
            preg_split('/[\r\n|]+/', $text) ?: [])));
    }

    /** @return array<string, string> */
    private function links(BrowseRoot $root, string $id, object $record): array
    {
        $links = [];
        $identities = match ($root) {
            BrowseRoot::Movies => ['IMDb' => [$id, 'https://www.imdb.com/title/tt', true],
                'TMDB' => [$record->tmdbid ?? null, 'https://www.themoviedb.org/movie/'], 'Trakt' => [$record->traktid ?? null, 'https://trakt.tv/movies/']],
            BrowseRoot::Tv => ['TVDB' => [$record->tvdb ?? null, 'https://thetvdb.com/?tab=series&id='],
                'TVMaze' => [$record->tvmaze ?? null, 'https://www.tvmaze.com/shows/'], 'Trakt' => [$record->trakt ?? null, 'https://trakt.tv/shows/'],
                'IMDb' => [$record->imdb ?? null, 'https://www.imdb.com/title/tt', true], 'TMDB' => [$record->tmdb ?? null, 'https://www.themoviedb.org/tv/']],
            default => [],
        };
        foreach ($identities as $label => $identity) {
            $value = preg_replace('/^tt/', '', (string) $identity[0]) ?? '';
            if (ctype_digit($value) && (int) $value > 0) {
                $links[$label] = $identity[1].(($identity[2] ?? false) ? str_pad($value, 7, '0', STR_PAD_LEFT) : $value);
            }
        }
        if ($root === BrowseRoot::Books && ! empty($record->isbn)) {
            $isbn = preg_replace('/[^0-9Xx]/', '', (string) $record->isbn) ?? '';
            if (in_array(strlen($isbn), [10, 13], true)) {
                $links['ISBNdb'] = 'https://isbndb.com/book/'.$isbn;
            }
        }
        if (($url = $this->webUrl((string) ($record->url ?? ''))) !== null) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $label = match (true) {
                str_ends_with($host, 'musicbrainz.org') => 'MusicBrainz', str_ends_with($host, 'apple.com') => 'iTunes',
                str_ends_with($host, 'goodreads.com') => 'Goodreads', str_ends_with($host, 'isbndb.com') => 'ISBNdb',
                str_ends_with($host, 'igdb.com') => 'IGDB', str_ends_with($host, 'steampowered.com') => 'Steam',
                str_ends_with($host, 'deezer.com') => 'Deezer', default => 'Website',
            };
            $links[$label] = $url;
        }

        return $links;
    }

    private function trailerUrl(string $trailer): ?string
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
