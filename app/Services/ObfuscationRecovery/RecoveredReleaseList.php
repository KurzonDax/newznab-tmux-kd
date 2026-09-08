<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

final class RecoveredReleaseList
{
    /** @param Collection<int, stdClass> $releases */
    public function addDetails(Collection $releases): void
    {
        $outcomes = DB::table('obfuscation_recovery_files as files')
            ->join('obfuscation_recovery_publications as recovery', function (JoinClause $join): void {
                $join->on('files.bundle_id', '=', 'recovery.canonical_bundle_id')
                    ->on('files.revision', '=', 'recovery.canonical_revision');
            })
            ->whereIn('recovery.id', $releases->pluck('publication_id'))
            ->get(['recovery.id as publication_id', 'files.file_id', 'files.enrichment_outcome'])
            ->groupBy('publication_id');

        foreach ($releases as $release) {
            $release->details = new RecoveredReleaseDetails($release,
                ($outcomes->get($release->publication_id) ?? collect())->pluck('enrichment_outcome', 'file_id')->all());
        }
    }

    public function query(): Builder
    {
        return DB::table('releases as releases')
            ->join('obfuscation_recovery_publications as recovery', function (JoinClause $join): void {
                $join->on('recovery.releases_id', '=', 'releases.id')
                    ->on('recovery.guid', '=', 'releases.guid');
            })
            ->where('recovery.state', 'published')
            ->whereIn('recovery.initialization_state', ['complete', 'failed'])
            ->whereNull('recovery.deleted_at')
            ->leftJoin('usenet_groups as groups', 'groups.id', '=', 'releases.groups_id')
            ->leftJoin('categories as category', 'category.id', '=', 'releases.categories_id')
            ->leftJoin('root_categories as root', 'root.id', '=', 'category.root_categories_id');
    }
}
