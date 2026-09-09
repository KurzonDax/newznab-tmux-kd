<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CollectionOwnership
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder|EloquentBuilder<TModel>  $query
     */
    public static function exclude(Builder|EloquentBuilder $query, string $column = 'collections.id'): void
    {
        if (! Schema::hasTable('reconciliation_claims')) {
            return;
        }
        $query->whereNotExists(static function (Builder $claim) use ($column): void {
            $claim->selectRaw('1')->from('reconciliation_claims as rc')->whereColumn('rc.collection_id', $column)
                ->where(static function (Builder $held): void {
                    $held->where(static function (Builder $pending): void {
                        $pending->where('rc.deadline', '>', now())->whereIn('rc.reason', ['pending', 'retry', 'budget_exhausted']);
                    })->orWhere(static function (Builder $accepted): void {
                        $accepted->whereNotNull('rc.release_id')->whereExists(static function (Builder $posting): void {
                            $posting->selectRaw('1')->from('reconciled_postings as rp')->whereColumn('rp.release_id', 'rc.release_id')->where('rp.state', '!=', 'published');
                        });
                    });
                });
        });
    }

    public static function protects(int $id): bool
    {
        $query = DB::table('collections')->where('id', $id);
        self::exclude($query);

        return ! $query->exists();
    }

    /**
     * Serialize header writes with source snapshot validation inside ingestion's transaction.
     *
     * @param  list<int>  $ids
     * @return list<int> Collections still present under the write lock
     */
    public static function ingest(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = DB::table('collections')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if (! Schema::hasTable('reconciliation_claims')) {
            return $ids;
        }
        $published = DB::table('collections')->whereIn('id', $ids)->whereIn('releases_id',
            DB::table('reconciled_postings')->where('state', 'published')->select('release_id'))->pluck('id')->all();
        if ($published !== []) {
            DB::table('collections')->whereIn('id', $published)->update(['releases_id' => null, 'filecheck' => 0]);
            DB::table('reconciliation_claims')->whereIn('collection_id', $published)->delete();
        }
        DB::table('reconciliation_claims')->whereIn('collection_id', $ids)->update(['revision' => 'changed']);

        return $ids;
    }
}
