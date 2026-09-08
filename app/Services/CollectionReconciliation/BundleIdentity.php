<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BundleIdentity
{
    public static function allowsSingleTitle(int $releaseId): bool
    {
        return ! Schema::hasTable('reconciled_postings') || ! DB::table('releases')->where('id', $releaseId)
            ->whereRaw('NOT ('.self::singleItemSql().')')->exists();
    }

    public static function availableSql(string $alias = 'releases'): string
    {
        return Schema::hasTable('reconciled_postings')
            ? "NOT EXISTS (SELECT 1 FROM reconciled_postings rp WHERE rp.release_id = {$alias}.id AND rp.state <> 'published') AND NOT EXISTS (SELECT 1 FROM reconciled_posting_inputs ri JOIN reconciled_postings rp ON rp.id = ri.posting_id WHERE ri.release_id = {$alias}.id AND rp.state <> 'published')"
            : '1 = 1';
    }

    public static function singleItemSql(string $alias = 'releases'): string
    {
        return Schema::hasTable('reconciled_postings')
            ? "NOT EXISTS (SELECT 1 FROM reconciled_postings rp WHERE rp.release_id = {$alias}.id AND rp.independent_videos = 1) AND (".self::availableSql($alias).')'
            : '1 = 1';
    }
}
