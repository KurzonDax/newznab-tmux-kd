<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\BrowseRoot;
use App\Enums\ReleaseSort;
use App\Support\MovieSearchQuery;
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
            $root === BrowseRoot::Movies && Schema::hasTable('movieinfo') => ['year' => 'm.year', 'genre' => 'm.genre', 'rating' => 'CAST(m.rating AS DECIMAL(4,2))'],
            $root === BrowseRoot::Tv && Schema::hasTable('videos') => ['year' => 'SUBSTR(m.started, 1, 4)', ...(Schema::hasTable('tv_info') ? ['network' => 'tv_info.publisher'] : [])],
            $root === BrowseRoot::Audio && Schema::hasTable('musicinfo') => ['year' => 'm.year', 'genre' => 'genres.title', 'label' => 'm.publisher', 'artist' => 'm.artist'],
            $root === BrowseRoot::Console && Schema::hasTable('consoleinfo') => ['year' => 'SUBSTR(m.releasedate, 1, 4)', 'genre' => 'genres.title', 'platform' => 'm.platform', 'publisher' => 'm.publisher'],
            $root === BrowseRoot::Games && Schema::hasTable('gamesinfo') => ['year' => 'SUBSTR(m.releasedate, 1, 4)', 'genre' => 'genres.title', 'platform' => "'PC'", 'publisher' => 'm.publisher'],
            $root === BrowseRoot::Books && Schema::hasTable('bookinfo') => ['year' => 'SUBSTR(m.publishdate, 1, 4)', 'genre' => 'm.genre', 'author' => 'm.author'],
            $root === BrowseRoot::Adult => ['year' => 'SUBSTR(r.postdate, 1, 4)'],
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
            } elseif ($key === 'rating') {
                if (preg_match('/^[1-9]$/', $filters[$key]) === 1) {
                    $query->whereRaw($column.' >= ?', [(int) $filters[$key]]);
                }
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

    /** @param array<string, string> $filters */
    public function searchMovies(Builder $query, string $text, array $filters): void
    {
        $search = MovieSearchQuery::fromInput(['q' => $text, ...$filters]);
        foreach ($search->termsByField() as $field => $terms) {
            foreach ($terms as $term) {
                $columns = $field === 'all' ? ['title', 'actors', 'director', 'plot'] : [$field];
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
                $query->where(function (Builder $words) use ($columns, $pattern): void {
                    foreach ($columns as $column) {
                        $words->orWhereRaw('m.'.$column." LIKE ? ESCAPE '!'", [$pattern]);
                    }
                });
            }
        }
    }

    /** @return array<string, list<string>> */
    public function options(Builder $query, BrowseRoot $root): array
    {
        $options = [];
        foreach ($this->fields($root) as $key => $column) {
            if (in_array($key, ['rating', 'artist'], true)) {
                continue;
            }
            if ($key === 'year') {
                $options[$key] = YearRange::years();

                continue;
            }
            $values = (clone $query)->selectRaw($column.' as value')->distinct()->pluck('value');
            $options[$key] = $values->flatMap(static fn ($value): array => $key === 'genre'
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
