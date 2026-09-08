<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;
use InvalidArgumentException;

final class RecoveryLateCapture
{
    /** @param list<array<string,mixed>> $rows */
    public static function invalidate(Connection $connection, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $families = [];
        foreach ($rows as $row) {
            $key = $row['profile'] === RecoveryAlgorithm::Media->value ? 'media' : $row['key_digest'];
            $families[$row['profile']] ??= ['context' => $row, 'first' => $row['embedded_timestamp_ms'],
                'last' => $row['embedded_timestamp_ms'], 'partitions' => []];
            $families[$row['profile']]['first'] = min($families[$row['profile']]['first'], $row['embedded_timestamp_ms']);
            $families[$row['profile']]['last'] = max($families[$row['profile']]['last'], $row['embedded_timestamp_ms']);
            $families[$row['profile']]['partitions'][$key][] = (int) $row['embedded_timestamp_ms'];
        }
        $affected = [];
        foreach ($families as $family) {
            $context = $family['context'];
            $media = $context['profile'] === RecoveryAlgorithm::Media->value;
            $gap = $media ? 30000 : 3000;
            $bundles = $connection->table('obfuscation_recovery_bundles')->where('groups_id', $context['groups_id'])
                ->where('profile', $context['profile'])->where('source_epoch', $context['source_epoch'])
                ->where('capture_generation', $context['capture_generation'])
                ->whereBetween('start_ms', [max(0, $family['first'] - 21600000 - $gap), $family['last'] + $gap])
                ->where('end_ms', '>=', max(0, $family['first'] - $gap))->limit(1001)->get();
            if ($bundles->count() > 1000) {
                throw new InvalidArgumentException('capture_invalidation_cap');
            }
            foreach ($family['partitions'] as &$timestamps) {
                sort($timestamps, SORT_NUMERIC);
            }
            unset($timestamps);
            foreach ($bundles as $bundle) {
                $timestamps = $family['partitions'][$media ? 'media' : $bundle->key_digest] ?? [];
                if (self::overlaps($timestamps, max(0, (int) $bundle->start_ms - $gap), (int) $bundle->end_ms + $gap)) {
                    $affected[(int) $bundle->id] = true;
                }
            }
        }
        ksort($affected, SORT_NUMERIC);
        foreach (array_keys($affected) as $id) {
            $bundle = $connection->table('obfuscation_recovery_bundles')->where('id', $id)->lockForUpdate()->first();
            if ($bundle === null || in_array($bundle->state, RecoveryOwnership::INACTIVE_STATES, true)) {
                continue;
            }
            if ($bundle->state === 'published' || ($bundle->publication_id !== null
                && $connection->table('obfuscation_recovery_publications')->where('id', $bundle->publication_id)->where('state', 'published')->exists())) {
                $connection->table('obfuscation_recovery_bundles')->where('id', $id)
                    ->update(['reason' => 'late_membership_conflict', 'updated_at' => now()]);

                continue;
            }
            $connection->table('obfuscation_recovery_bundles')->where('id', $id)->update([
                'revision' => (int) $bundle->revision + 1, 'sealed_plan' => null, 'manifest_verified_at' => null,
                'snapshot_digest' => null, 'state' => 'collecting', 'reason' => 'late_membership_revision',
                'membership_changed_at' => now(), 'claim_token' => null, 'claim_expires_at' => null,
                'next_action_at' => now()->addMinutes(120), 'updated_at' => now(),
            ]);
            $connection->table('obfuscation_recovery_work')->where('bundle_id', $id)
                ->whereIn('status', ['pending', 'claimed'])->update([
                    'status' => 'obsolete', 'claim_token' => null, 'claim_expires_at' => null, 'updated_at' => now(),
                ]);
        }
    }

    /** @param list<int> $timestamps */
    private static function overlaps(array $timestamps, int $first, int $last): bool
    {
        $left = 0;
        $right = count($timestamps);
        while ($left < $right) {
            $middle = intdiv($left + $right, 2);
            if ($timestamps[$middle] < $first) {
                $left = $middle + 1;
            } else {
                $right = $middle;
            }
        }

        return isset($timestamps[$left]) && $timestamps[$left] <= $last;
    }
}
