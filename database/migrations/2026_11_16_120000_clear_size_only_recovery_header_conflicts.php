<?php

declare(strict_types=1);

use App\Services\ObfuscationRecovery\RecoveryDirty;
use App\Services\ObfuscationRecovery\RecoveryLateCapture;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Header conflict checks used to treat a size-only difference as a conflict. No column records why a flag was set,
 * so every flag in the primary source epoch is cleared and its candidates rebuilt; a header that really differs is
 * flagged again by the next differing sighting.
 */
return new class extends Migration
{
    public function up(): void
    {
        $epoch = DB::table('obfuscation_recovery_controls')->where('scope', 'primary')->value('epoch');
        if ($epoch === null) {
            return;
        }
        // Select once before clearing: paging a query that filters on the flag while the loop clears it skips rows.
        $selected = DB::table('obfuscation_recovery_headers')->where('source_epoch', $epoch)->where('metadata_conflict', true)
            ->orderBy('groups_id')->orderBy('capture_generation')->orderBy('embedded_timestamp_ms')->orderBy('id')
            ->get(['id', 'groups_id', 'capture_generation', 'embedded_timestamp_ms']);
        $splits = [];
        foreach ($selected as $row) {
            $splits[(int) $row->groups_id.':'.(int) $row->capture_generation][] = $row;
        }
        $connection = DB::connection();
        if (! $connection instanceof Connection) {
            throw new RuntimeException('unsupported_recovery_connection');
        }
        foreach ($splits as $rows) {
            // RecoveryLateCapture reads the group and generation from the first row, so a batch never mixes them.
            foreach (array_chunk($rows, 100) as $batch) {
                $connection->transaction(static function () use ($connection, $batch): void {
                    $connection->table('obfuscation_recovery_controls')->where('scope', 'group:'.(int) $batch[0]->groups_id)->lockForUpdate()->first();
                    $headers = $connection->table('obfuscation_recovery_headers')->whereIn('id', array_column($batch, 'id'))
                        ->where('metadata_conflict', true)->orderBy('embedded_timestamp_ms')->orderBy('id')->lockForUpdate()->get();
                    if ($headers->isEmpty()) {
                        return;
                    }
                    $connection->table('obfuscation_recovery_headers')->whereIn('id', $headers->pluck('id')->all())->update(['metadata_conflict' => false]);
                    $cleared = $headers->map(static fn (object $header): array => [...(array) $header, 'metadata_conflict' => 0])->all();
                    RecoveryLateCapture::invalidate($connection, $cleared);
                    RecoveryDirty::mark($connection, $cleared);
                });
            }
        }
    }

    public function down(): void
    {
        // Cleared flags cannot be told apart from never-set ones; a header that really differs is flagged again on capture.
    }
};
