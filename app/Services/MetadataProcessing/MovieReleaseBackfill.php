<?php

declare(strict_types=1);

namespace App\Services\MetadataProcessing;

use App\Models\MovieInfo;
use App\Models\Release;
use App\Services\CollectionReconciliation\BundleIdentity;
use App\Services\ObfuscationRecovery\RecoveryIdentityPolicy;
use App\Support\ReleaseSearchIndexSync;
use Illuminate\Support\Facades\DB;

final class MovieReleaseBackfill
{
    public function forMovie(MovieInfo $movie): int
    {
        if (! imdb_id_is_valid($movie->imdbid)) {
            return 0;
        }

        $linked = 0;
        do {
            $count = DB::transaction(function () use ($movie): int {
                $query = Release::query()->where('imdbid', $movie->imdbid)->whereNull('movieinfo_id')
                    ->whereRaw(RecoveryIdentityPolicy::singleItemSql())->whereRaw(BundleIdentity::singleItemSql());
                $ids = (clone $query)->orderBy('id')->limit(500)->lockForUpdate()->pluck('id')->all();
                if ($ids === []) {
                    return 0;
                }

                $query->whereIn('id', $ids)->update(['movieinfo_id' => $movie->id]);
                DB::afterCommit(static fn () => ReleaseSearchIndexSync::forIds($ids));

                return count($ids);
            });
            $linked += $count;
        } while ($count === 500);

        return $linked;
    }
}
