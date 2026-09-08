<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;

final class RecoveryDirty
{
    /** @param list<array<string,mixed>> $rows */
    public static function mark(Connection $connection, array $rows): void
    {
        $keys = [];
        $identity = new RecoveryIdentity;
        foreach ($rows as $row) {
            $partition = $row['profile'] === RecoveryAlgorithm::Media->value ? (string) $row['advertised_total'] : $row['key_digest'];
            $scope = $identity->digest([$row['source_epoch'], (string) $row['groups_id'], (string) $row['capture_generation'], $row['profile'], $partition]);
            $keys[$scope] ??= ['scope_digest' => $scope, 'source_epoch' => $row['source_epoch'], 'groups_id' => $row['groups_id'],
                'capture_generation' => $row['capture_generation'], 'profile' => $row['profile'], 'partition_value' => $partition,
                'first_ms' => $row['embedded_timestamp_ms'], 'last_ms' => $row['embedded_timestamp_ms'],
                'version' => 1, 'membership_changed_at' => now(), 'next_action_at' => now()];
            $keys[$scope]['first_ms'] = min($keys[$scope]['first_ms'], $row['embedded_timestamp_ms']);
            $keys[$scope]['last_ms'] = max($keys[$scope]['last_ms'], $row['embedded_timestamp_ms']);
        }
        if ($keys === []) {
            return;
        }
        ksort($keys, SORT_STRING);
        $sqlite = $connection->getDriverName() === 'sqlite';
        foreach (array_chunk(array_values($keys), 250) as $chunk) {
            $connection->table('obfuscation_recovery_dirty')->upsert($chunk, ['scope_digest'], [
                'first_ms' => $connection->raw($sqlite ? 'MIN(first_ms, excluded.first_ms)' : 'LEAST(first_ms, VALUES(first_ms))'),
                'last_ms' => $connection->raw($sqlite ? 'MAX(last_ms, excluded.last_ms)' : 'GREATEST(last_ms, VALUES(last_ms))'),
                'version' => $connection->raw('version + 1'), 'membership_changed_at', 'next_action_at',
            ]);
        }
    }
}
