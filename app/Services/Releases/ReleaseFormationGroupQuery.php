<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Services\Nzb\NzbService;
use App\Support\SchemaCapabilities;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Groups with source work or a publication that can still be resumed after source cleanup. */
final class ReleaseFormationGroupQuery
{
    public static function query(): Builder
    {
        return DB::table('usenet_groups')->where(static function (Builder $groups): void {
            $groups->whereExists(static fn (Builder $collections): Builder => $collections->selectRaw('1')->from('collections')
                ->whereColumn('collections.groups_id', 'usenet_groups.id'))
                ->orWhereExists(static fn (Builder $releases): Builder => $releases->selectRaw('1')->from('releases')
                    ->whereColumn('releases.groups_id', 'usenet_groups.id')->where('releases.nzbstatus', NzbService::NZB_NONE));

            if (SchemaCapabilities::hasTable('reconciled_artifacts')) {
                $groups->orWhereIn('usenet_groups.id', DB::table('reconciled_artifacts as a')
                    ->join('releases as r', 'r.id', '=', 'a.release_id')->select('r.groups_id')
                    ->where(static function (Builder $artifacts): void {
                        $artifacts->where('a.search_pending', true)->orWhereExists(static function (Builder $operations): void {
                            $operations->selectRaw('1')->from('reconciled_artifact_operations as o')
                                ->whereColumn('o.release_id', 'a.release_id')
                                ->where(static function (Builder $states): void {
                                    $states->where('o.state', 'prepared')->orWhere(static function (Builder $committed): void {
                                        $committed->where('o.state', 'committed')->whereExists(static fn (Builder $sources): Builder => $sources
                                            ->selectRaw('1')->from('reconciled_artifact_sources as s')
                                            ->whereColumn('s.operation_id', 'o.id')->where('s.cleanup_pending', true));
                                    });
                                });
                        });
                    }));
            }

            if (SchemaCapabilities::hasTable('reconciled_postings')) {
                $groups->orWhereIn('usenet_groups.id', DB::table('reconciled_postings as p')
                    ->join('releases as r', 'r.id', '=', 'p.release_id')->select('r.groups_id')
                    ->where('p.state', '!=', 'published')->whereNull('p.review_digest')->whereNotNull('p.original_nzb'));
            }
        });
    }
}
