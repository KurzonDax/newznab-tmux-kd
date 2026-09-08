<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Enums\HeaderScanDirection;
use Illuminate\Database\Connection;

final class RecoveryScanWindow
{
    public static function record(Connection $connection, RecoveryScanContext $context, RecoveryConfig $config): void
    {
        if ($context->direction !== HeaderScanDirection::Head) {
            return;
        }
        $connection->table('obfuscation_recovery_scan_windows')->insertOrIgnore([
            'scan_id' => $context->scanId, 'groups_id' => $context->groupId, 'source_epoch' => $context->sourceEpoch,
            'capture_generation' => $context->generation, 'requested_first' => $context->first, 'requested_last' => $context->last,
            'next_gap_at' => now()->addMinutes(2), 'expires_at' => now()->addHours(min(876000, $config->retentionHours)), 'created_at' => now(),
        ]);
    }
}
