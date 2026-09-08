<?php

declare(strict_types=1);

namespace App\Services\Par2Sidecar;

use App\Models\Release;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/** A started filesystem handoff remains protected after its worker's lease expires. */
final class SidecarMutationProtection
{
    /**
     * @param  Builder<Release>  $query
     * @return Builder<Release>
     */
    public static function apply(Builder $query, string $table = 'releases', ?int $operationId = null): Builder
    {
        if (! Schema::hasTable('par2_sidecar_operations')) {
            return $query;
        }

        return $query->whereNotExists(static function (\Illuminate\Database\Query\Builder $operations) use ($table, $operationId): void {
            $operations->selectRaw('1')->from('par2_sidecar_operations as sidecar_mutation')
                ->whereIn('sidecar_mutation.phase', ['nzb_writing', 'nzb_written', 'delete_pending'])
                ->where(static function (\Illuminate\Database\Query\Builder $members) use ($table): void {
                    $members->whereColumn('sidecar_mutation.target_id', $table.'.id')
                        ->orWhere(static fn ($source) => $source->whereColumn('sidecar_mutation.source_id', $table.'.id')
                            ->where('sidecar_mutation.phase', '<>', 'delete_pending'));
                });
            if ($operationId !== null) {
                $operations->where('sidecar_mutation.id', '<>', $operationId);
            }
        });
    }
}
