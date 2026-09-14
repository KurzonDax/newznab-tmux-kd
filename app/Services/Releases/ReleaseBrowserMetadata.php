<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\BrowseRoot;
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
                BrowseRoot::Books => ['bookinfo', 'bookinfo_id'],
                default => null,
            };
            if ($source !== null) {
                $query->leftJoin($source[0].' as m', 'm.id', '=', 'r.'.$source[1]);
                if (in_array($root, [BrowseRoot::Audio, BrowseRoot::Console], true)) {
                    $query->leftJoin('genres', 'genres.id', '=', 'm.genres_id');
                }
            }
        }
    }

    /** @param array<string, string> $filters */
    public function filter(Builder $query, BrowseRoot $root, array $filters): void
    {
        foreach ($this->fields($root) as $key => $column) {
            if (! isset($filters[$key])) {
                continue;
            }
            if ($key === 'genre') {
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
            if (in_array($key, ['rating', 'artist'], true)) {
                continue;
            }
            $values = (clone $query)->selectRaw($column.' as value')->distinct()->pluck('value');
            $options[$key] = $values->flatMap(static fn ($value): array => $key === 'genre'
                ? preg_split('/[,|]/', (string) $value) ?: [] : [(string) $value])
                ->map(static fn (string $value): string => trim($value))->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
            if ($key === 'year') {
                $options[$key] = array_reverse($options[$key]);
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    public function sorts(BrowseRoot $root): array
    {
        $sorts = ['newest' => 'Newest release', 'title' => 'Title A–Z'];
        if (! in_array($root, [BrowseRoot::Movies, BrowseRoot::Tv, BrowseRoot::Audio], true)) {
            return $sorts;
        }
        foreach (['year' => 'Year', 'rating' => 'Rating', 'artist' => 'Artist'] as $key => $label) {
            if (isset($this->fields($root)[$key])) {
                $sorts[$key] = $label;
            }
        }

        return $sorts;
    }
}
