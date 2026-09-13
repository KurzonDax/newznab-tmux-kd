<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('obfuscation_recovery_scan_windows', 'span_bucket')) {
            Schema::table('obfuscation_recovery_scan_windows', function (Blueprint $table): void {
                $expression = 'CASE';
                for ($bucket = 0; $bucket < 15; $bucket++) {
                    $expression .= ' WHEN CAST(requested_last AS SIGNED) - CAST(requested_first AS SIGNED) < '.(1 << (4 * ($bucket + 1))).' THEN '.$bucket;
                }
                $table->unsignedTinyInteger('span_bucket')->virtualAs($expression.' ELSE 15 END');
                $table->index(['groups_id', 'source_epoch', 'capture_generation', 'span_bucket', 'requested_first', 'requested_last'], 'recovery_window_span');
            });
        }
        if (! Schema::hasTable('obfuscation_recovery_frontier_policy')) {
            Schema::create('obfuscation_recovery_frontier_policy', function (Blueprint $table): void {
                $table->string('policy', 48)->primary();
                $table->timestamp('cutover_at', 6);
                $table->unsignedBigInteger('last_attempt_id');
                $table->unsignedBigInteger('last_request_id');
            });
        }
        if (! Schema::hasTable('obfuscation_recovery_frontier_allowances')) {
            Schema::create('obfuscation_recovery_frontier_allowances', function (Blueprint $table): void {
                $table->char('owner_digest', 64)->primary();
                $table->unsignedBigInteger('normal_bytes');
                $table->json('history');
                $table->timestamp('granted_at', 6);
            });
        }
        DB::table('obfuscation_recovery_frontier_policy')->insertOrIgnore([
            'policy' => 'connected-fragments-v1', 'cutover_at' => now('UTC'),
            'last_attempt_id' => DB::table('obfuscation_recovery_attempts')->max('id') ?? 0,
            'last_request_id' => DB::table('obfuscation_recovery_frontier_requests')->max('id') ?? 0,
        ]);
    }

    public function down(): void
    {
        // Lifetime spend and the eligibility cutover must survive rollback/reapplication.
    }
};
