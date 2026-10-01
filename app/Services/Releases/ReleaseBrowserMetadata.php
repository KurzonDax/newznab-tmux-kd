<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\BrowseRoot;
use App\Enums\ReleaseSort;
use App\Support\YearRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ReleaseBrowserMetadata
{
    /** @return array<string, string> */
    public function fields(BrowseRoot $root): array
    {
        return match (true) {
            $root === BrowseRoot::Movies && Schema::hasTable('movieinfo') => ['year' => 'm.year', 'genre' => 'm.genre'],
            $root === BrowseRoot::Tv && Schema::hasTable('videos') => ['year' => 'SUBSTR(m.started, 1, 4)', ...(Schema::hasTable('tv_info') ? ['network' => 'tv_info.publisher'] : [])],
            $root === BrowseRoot::Audio && Schema::hasTable('musicinfo') => ['year' => 'm.year', 'genre' => 'genres.title', 'label' => 'm.publisher', 'artist' => 'm.artist'],
            $root === BrowseRoot::Console && Schema::hasTable('consoleinfo') => ['year' => 'SUBSTR(m.releasedate, 1, 4)', ...(Schema::hasTable('console_genres') ? ['genre' => 'console_genre_titles.title'] : []), 'platform' => 'm.platform', 'publisher' => 'm.publisher'],
            $root === BrowseRoot::Games && Schema::hasTable('gamesinfo') => ['year' => 'SUBSTR(m.releasedate, 1, 4)', 'genre' => 'genres.title', 'platform' => "'PC'", 'publisher' => 'm.publisher'],
            $root === BrowseRoot::Books && Schema::hasTable('bookinfo') => ['year' => 'SUBSTR(m.publishdate, 1, 4)', 'genre' => 'm.genre', 'author' => 'm.author'],
            default => [],
        };
    }

    public function join(Builder $query, BrowseRoot $root): void
    {
        if ($root === BrowseRoot::Movies && $this->fields($root) !== []) {
            $query->leftJoin('movieinfo as m', 'm.imdbid', '=', 'r.imdbid');
        } elseif ($root === BrowseRoot::Tv && $this->fields($root) !== []) {
            $query->leftJoin('videos as m', 'm.id', '=', 'r.videos_id');
            if (Schema::hasTable('tv_info')) {
                $query->leftJoin('tv_info', 'tv_info.videos_id', '=', 'm.id');
            }
        } elseif ($this->fields($root) !== []) {
            $source = match ($root) {
                BrowseRoot::Audio => ['musicinfo', 'musicinfo_id'],
                BrowseRoot::Console => ['consoleinfo', 'consoleinfo_id'],
                BrowseRoot::Games => ['gamesinfo', 'gamesinfo_id'],
                BrowseRoot::Books => ['bookinfo', 'bookinfo_id'],
                default => null,
            };
            if ($source !== null) {
                $query->leftJoin($source[0].' as m', 'm.id', '=', 'r.'.$source[1]);
                if (in_array($root, [BrowseRoot::Audio, BrowseRoot::Console, BrowseRoot::Games], true)) {
                    $query->leftJoin('genres', 'genres.id', '=', 'm.genres_id');
                }
            }
        }
    }

    /** @param array<string, string> $filters */
    public function filter(Builder $query, BrowseRoot $root, array $filters): void
    {
        $yearRange = YearRange::fromInput($filters['year'] ?? null, $filters['year_from'] ?? null, $filters['year_to'] ?? null);
        foreach ($this->fields($root) as $key => $column) {
            if ($key === 'year' && $yearRange !== null) {
                if ($yearRange->from !== null) {
                    $query->whereRaw($column.' >= ?', [(string) $yearRange->from]);
                }
                if ($yearRange->to !== null) {
                    $query->whereRaw($column.' <= ?', [(string) $yearRange->to]);
                }

                continue;
            }
            if (! isset($filters[$key])) {
                continue;
            }
            if ($key === 'year') {
                continue;
            } elseif ($key === 'genre' && $root === BrowseRoot::Console) {
                // One row per genre (console_genres), so a game matches the title of any of its genres.
                $query->whereExists(static fn (Builder $genre) => $genre->selectRaw('1')->from('console_genres as console_genre_links')
                    ->join('genres as console_genre_titles', 'console_genre_titles.id', '=', 'console_genre_links.genres_id')
                    ->whereColumn('console_genre_links.consoleinfo_id', 'm.id')->where('console_genre_titles.title', $filters[$key]));
            } elseif ($key === 'genre') {
                $normalized = "REPLACE(REPLACE(REPLACE($column, ' | ', ','), '|', ','), ', ', ',')";
                $delimited = DB::getDriverName() === 'sqlite' ? "(',' || $normalized || ',')" : "CONCAT(',', $normalized, ',')";
                $value = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters[$key]);
                $query->whereRaw($delimited." LIKE ? ESCAPE '!'", ['%,'.$value.',%']);
            } else {
                $query->whereRaw($column.' = ?', [$filters[$key]]);
            }
        }
    }

    /** @return array<string, list<string>> */
    public function options(Builder $query, BrowseRoot $root): array
    {
        $options = [];
        foreach ($this->fields($root) as $key => $column) {
            if ($key === 'artist') {
                continue;
            }
            if ($key === 'year') {
                $options[$key] = YearRange::years();

                continue;
            }
            $source = clone $query;
            if ($key === 'genre' && $root === BrowseRoot::Console) {
                // The genres of the games behind the releases the listing holds, each a single name.
                $source->join('console_genres as console_genre_links', 'console_genre_links.consoleinfo_id', '=', 'm.id')
                    ->join('genres as console_genre_titles', 'console_genre_titles.id', '=', 'console_genre_links.genres_id');
            }
            $values = $source->selectRaw($column.' as value')->distinct()->pluck('value');
            $options[$key] = $values->flatMap(static fn ($value): array => $key === 'genre' && $root !== BrowseRoot::Console
                ? preg_split('/[,|]/', (string) $value) ?: [] : [(string) $value])
                ->map(static fn (string $value): string => trim($value))->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
        }

        return $options;
    }

    /** @return array<string, string> */
    public function sorts(BrowseRoot $root): array
    {
        return ReleaseSort::options();
    }
}
