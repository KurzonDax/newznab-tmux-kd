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

    /** @return array<string, string> */
    public function sorts(BrowseRoot $root): array
    {
        return ReleaseSort::options();
    }
}
