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
     * @param  list<int>|null  $populationIds
     */
    public static function exclude(Builder|EloquentBuilder $query, string $column = 'collections.id', ?array $populationIds = null, bool $currentRead = false): void
    {
        self::excludeArtifactSources($query, $column, $populationIds, $currentRead);
        $hasAdmissions = Schema::hasTable('reconciliation_admissions');
        if ($hasAdmissions) {
            $query->whereNotExists(static function (Builder $admission) use ($column, $populationIds, $currentRead): void {
                $admission->selectRaw('1')->from('reconciliation_admissions as ra')->whereColumn('ra.collection_id', $column)
                    ->when($populationIds !== null, static fn ($query) => $query->whereIn('ra.collection_id', $populationIds))
                    ->when($currentRead, static fn ($query) => $query->lockForUpdate())
                    ->where('ra.state', 'admitted')->where('ra.expires_at', '>', now());
            });
        }
        if (! Schema::hasTable('reconciliation_claims')) {
            return;
        }
        $query->whereNotExists(static function (Builder $claim) use ($column, $hasAdmissions, $populationIds, $currentRead): void {
            $claim->selectRaw('1')->from('reconciliation_claims as rc')->whereColumn('rc.collection_id', $column)
                ->when($populationIds !== null, static fn ($query) => $query->whereIn('rc.collection_id', $populationIds))
                ->when($currentRead, static fn ($query) => $query->lockForUpdate())
                ->where(static function (Builder $held) use ($hasAdmissions, $currentRead): void {
                    $held->where(static function (Builder $pending) use ($hasAdmissions): void {
                        $pending->where('rc.deadline', '>', now())->whereIn('rc.reason', ['pending', 'retry', 'budget_exhausted']);
                        if ($hasAdmissions) {
                            $pending->whereRaw('1 = 0');
                        }
                    })->orWhere(static function (Builder $accepted) use ($currentRead): void {
                        $accepted->whereNotNull('rc.release_id')->whereExists(static function (Builder $posting) use ($currentRead): void {
                            $posting->selectRaw('1')->from('reconciled_postings as rp')->whereColumn('rp.release_id', 'rc.release_id')->where('rp.state', '!=', 'published')
                                ->when($currentRead, static fn ($query) => $query->lockForUpdate());
                        });
                    });
                });
        });
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder|EloquentBuilder<TModel>  $query
     * @param  list<int>|null  $populationIds
     */
    public static function excludeArtifactSources(Builder|EloquentBuilder $query, string $column = 'collections.id', ?array $populationIds = null, bool $currentRead = false): void
    {
        if (Schema::hasTable('reconciled_artifact_sources')) {
            $query->whereNotExists(static function (Builder $operation) use ($column, $populationIds, $currentRead): void {
                $operation->selectRaw('1')->from('reconciled_artifact_sources as ras')
                    ->join('reconciled_artifact_operations as rao', 'rao.id', '=', 'ras.operation_id')
                    ->whereColumn('ras.collection_id', $column)->where('ras.cleanup_pending', true)
                    ->when($populationIds !== null, static fn ($query) => $query->whereIn('ras.collection_id', $populationIds))
                    ->when($currentRead, static fn ($query) => $query->lockForUpdate())
                    ->whereIn('rao.state', ['prepared', 'committed', 'conflict']);
            });
        }
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
        if (Schema::hasTable('reconciliation_admissions')) {
            DB::table('reconciliation_admissions')->whereIn('collection_id', $ids)->where('state', 'disproved')
                ->update(['state' => 'invalidated', 'revision' => 'changed']);
            DB::table('reconciliation_admissions')->whereIn('collection_id', $ids)->where('state', 'admitted')
                ->update(['revision' => 'changed']);
        }
        DB::table('reconciliation_claims')->whereIn('collection_id', $ids)->update(['revision' => 'changed']);

        return $ids;
    }
}
