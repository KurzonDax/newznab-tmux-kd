<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('obfuscation_recovery_frontier_requests', 'superseded_by')) {
            Schema::table('obfuscation_recovery_frontier_requests', function (Blueprint $table): void {
                $table->unsignedBigInteger('superseded_by')->nullable()->index('recovery_frontier_successor');
                $table->unsignedBigInteger('reserved_attempt_id')->nullable();
                $table->index(['source_epoch', 'groups_id', 'evidence_version', 'requested_first', 'id'], 'recovery_frontier_history');
            });
        }
        if (! Schema::hasTable('obfuscation_recovery_frontier_installs')) {
            Schema::create('obfuscation_recovery_frontier_installs', function (Blueprint $table): void {
                $table->unsignedBigInteger('attempt_id')->primary();
                $table->unsignedBigInteger('request_id')->index('recovery_frontier_install_request');
                $table->unsignedBigInteger('work_id');
                $table->uuid('claim_token');
                $table->timestamp('installed_at', 6)->nullable();
                $table->char('evidence_digest', 64)->nullable();
            });
        }
        if (! Schema::hasColumn('obfuscation_recovery_frontier_targets', 'authority_digest')) {
            Schema::table('obfuscation_recovery_frontier_targets', function (Blueprint $table): void {
                $table->char('authority_digest', 64)->default('');
                $table->dropUnique('recovery_frontier_target');
                $table->unique(['request_id', 'bundle_id', 'revision', 'first_article', 'last_article', 'authority_digest'], 'recovery_frontier_target');
            });
        }
    }

    public function down(): void
    {
        // Request provenance and reservation fences survive rollback/reapplication.
    }
};
