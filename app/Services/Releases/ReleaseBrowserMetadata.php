<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\BrowseRoot;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

final class ReleaseBrowserMetadata
{
    public function join(Builder $query, BrowseRoot $root): void
    {
        if ($root === BrowseRoot::Movies && Schema::hasTable('movieinfo')) {
            $query->leftJoin('movieinfo as m', 'm.imdbid', '=', 'r.imdbid');
        } elseif ($root === BrowseRoot::Tv && Schema::hasTable('videos')) {
            $query->leftJoin('videos as m', 'm.id', '=', 'r.videos_id');
            if (Schema::hasTable('tv_info')) {
                $query->leftJoin('tv_info', 'tv_info.videos_id', '=', 'm.id');
            }
        }
    }
}
