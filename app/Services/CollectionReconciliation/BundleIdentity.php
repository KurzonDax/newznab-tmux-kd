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
        if (! Schema::hasTable('reconciled_postings')) {
            return '1 = 1';
        }
        $grammar = DB::connection()->getQueryGrammar();
        $postings = $grammar->wrapTable('reconciled_postings');
        $inputs = $grammar->wrapTable('reconciled_posting_inputs');

        return ArtifactPublication::availableSql($alias)." AND NOT EXISTS (SELECT 1 FROM {$postings} rp WHERE rp.release_id = {$alias}.id AND rp.state <> 'published') AND NOT EXISTS (SELECT 1 FROM {$inputs} ri JOIN {$postings} rp ON rp.id = ri.posting_id WHERE ri.release_id = {$alias}.id AND rp.state <> 'published')";
    }

    public static function singleItemSql(string $alias = 'releases'): string
    {
        $postings = DB::connection()->getQueryGrammar()->wrapTable('reconciled_postings');

        return Schema::hasTable('reconciled_postings')
            ? "NOT EXISTS (SELECT 1 FROM {$postings} rp WHERE rp.release_id = {$alias}.id AND rp.independent_videos = 1) AND (".self::availableSql($alias).')'
            : '1 = 1';
    }
}
