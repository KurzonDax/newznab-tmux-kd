<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class RecoveryReleaseGate
{
    /** @template T of Model
     * @param  Builder|EloquentBuilder<T>  $query
     */
    public static function excludePending(Builder|EloquentBuilder $query, string $table = 'releases'): void
    {
        $query->whereRaw(self::availableSql($table.'.id'));
    }

    public static function availableSql(string $column = 'r.id', ?ConnectionInterface $connection = null): string
    {
        $connection ??= DB::connection();
        if (! $connection instanceof Connection) {
            throw new \LogicException('unsupported_database_connection');
        }
        if (! $connection->getSchemaBuilder()->hasTable('obfuscation_recovery_publications')) {
            return '1 = 1';
        }
        $grammar = $connection->getQueryGrammar();

        return 'NOT EXISTS (SELECT 1 FROM '.$grammar->wrapTable('obfuscation_recovery_publications').' recovery_initialization'
            .' WHERE recovery_initialization.releases_id = '.$grammar->wrap($column)
            ." AND recovery_initialization.state IN ('created', 'published') AND (recovery_initialization.initialization_state = 'pending' OR (recovery_initialization.enrichment_outcome = 'enrichment_pending' AND recovery_initialization.enrichment_next_attempt_at > '".now()->format('Y-m-d H:i:s')."')))";
    }

    public static function pending(int $releaseId): bool
    {
        return ! DB::table('releases')->where('id', $releaseId)->whereRaw(self::availableSql('releases.id'))->exists();
    }
}
