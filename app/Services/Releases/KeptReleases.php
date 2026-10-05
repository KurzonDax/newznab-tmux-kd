<?php

declare(strict_types=1);

namespace App\Services\Releases;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Releases an operator imported on purpose, one NZB at a time.
 *
 * Automated sweeps and inline executable discards leave a kept release alone; release
 * retention and deliberate operator deletion still remove it. Deleting the release removes
 * its mark through the foreign-key cascade.
 */
final class KeptReleases
{
    public const string TABLE = 'kept_releases';

    public static function mark(int $releaseId): void
    {
        DB::table(self::TABLE)->insertOrIgnore(['releases_id' => $releaseId, 'created_at' => now()]);
    }

    public static function isKept(int $releaseId): bool
    {
        return Schema::hasTable(self::TABLE)
            && DB::table(self::TABLE)->where('releases_id', $releaseId)->exists();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function exclude(Builder $query, string $table = 'releases'): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $query->whereNotExists(static function (\Illuminate\Database\Query\Builder $kept) use ($table): void {
            $kept->selectRaw('1')->from(self::TABLE)
                ->whereColumn(self::TABLE.'.releases_id', $table.'.id');
        });
    }
}
