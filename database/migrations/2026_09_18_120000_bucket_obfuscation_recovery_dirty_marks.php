<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('obfuscation_recovery_dirty', function (Blueprint $table): void {
            $table->unsignedBigInteger('bucket')->default(0)->after('partition_value');
            $table->dropUnique(['scope_digest']);
        });

        $minute = DB::getDriverName() === 'sqlite' ? 'embedded_timestamp_ms / 60000' : 'embedded_timestamp_ms DIV 60000';
        DB::transaction(function () use ($minute): void {
            $originals = DB::table('obfuscation_recovery_dirty')->get();
            foreach ($originals as $original) {
                $cells = DB::table('obfuscation_recovery_headers')
                    ->where('source_epoch', $original->source_epoch)->where('groups_id', $original->groups_id)
                    ->where('capture_generation', $original->capture_generation)->where('profile', $original->profile)
                    ->where($original->profile === 'nyuu-media-v1' ? 'advertised_total' : 'key_digest', $original->partition_value)
                    ->whereBetween('embedded_timestamp_ms', [$original->first_ms, $original->last_ms])
                    ->selectRaw($minute.' AS bucket, MIN(embedded_timestamp_ms) AS first_ms, MAX(embedded_timestamp_ms) AS last_ms')
                    ->groupByRaw($minute)->get();
                foreach ($cells as $cell) {
                    DB::table('obfuscation_recovery_dirty')->insert([
                        'scope_digest' => $original->scope_digest, 'source_epoch' => $original->source_epoch,
                        'groups_id' => $original->groups_id, 'capture_generation' => $original->capture_generation,
                        'profile' => $original->profile, 'partition_value' => $original->partition_value,
                        'bucket' => $cell->bucket, 'first_ms' => $cell->first_ms, 'last_ms' => $cell->last_ms,
                        'version' => 1, 'membership_changed_at' => $original->membership_changed_at,
                        'next_action_at' => now(), 'claim_token' => null, 'claim_expires_at' => null,
                    ]);
                }
                DB::table('obfuscation_recovery_dirty')->where('id', $original->id)->delete();
            }
        });

        Schema::table('obfuscation_recovery_dirty', fn (Blueprint $table) => $table->unique(['scope_digest', 'bucket'], 'recovery_dirty_cell'));
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $scopes = DB::table('obfuscation_recovery_dirty')->select('scope_digest')
                ->selectRaw('MIN(id) AS id, MIN(first_ms) AS first_ms, MAX(last_ms) AS last_ms, MAX(version) AS version, MAX(membership_changed_at) AS membership_changed_at, MIN(next_action_at) AS next_action_at')
                ->groupBy('scope_digest')->get();
            foreach ($scopes as $scope) {
                DB::table('obfuscation_recovery_dirty')->where('id', $scope->id)->update([
                    'first_ms' => $scope->first_ms, 'last_ms' => $scope->last_ms, 'version' => $scope->version,
                    'membership_changed_at' => $scope->membership_changed_at, 'next_action_at' => $scope->next_action_at,
                    'claim_token' => null, 'claim_expires_at' => null,
                ]);
                DB::table('obfuscation_recovery_dirty')->where('scope_digest', $scope->scope_digest)->where('id', '!=', $scope->id)->delete();
            }
        });
        Schema::table('obfuscation_recovery_dirty', function (Blueprint $table): void {
            $table->dropUnique('recovery_dirty_cell');
            $table->dropColumn('bucket');
            $table->unique('scope_digest');
        });
    }
};
