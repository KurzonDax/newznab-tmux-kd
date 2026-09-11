<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RecoveryCollectionOwnership
{
    /** @template T of Model
     * @param  Builder|EloquentBuilder<T>  $query
     * @param  list<int>|null  $populationIds
     */
    public static function exclude(Builder|EloquentBuilder $query, string $column = 'collections.id', ?array $populationIds = null, bool $currentRead = false): void
    {
        if (! Schema::hasTable('obfuscation_recovery_publications')) {
            return;
        }
        $query->whereNotExists(static fn (Builder $owned): Builder => $owned->selectRaw('1')
            ->from('obfuscation_recovery_publications as recovery_owner')
            ->whereColumn('recovery_owner.collections_id', $column)
            ->when($populationIds !== null, static fn ($owned) => $owned->whereIn('recovery_owner.collections_id', $populationIds))
            ->when($currentRead, static fn ($owned) => $owned->lockForUpdate())
            ->whereNotIn('recovery_owner.state', ['published', 'absorbed', 'duplicate_policy_discarded', 'tombstoned']));
    }

    public static function protects(int $collectionId): bool
    {
        $query = DB::table('collections')->where('id', $collectionId);
        self::exclude($query);

        return ! $query->exists();
    }
}
