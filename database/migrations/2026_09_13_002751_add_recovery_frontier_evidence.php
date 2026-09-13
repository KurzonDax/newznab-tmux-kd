<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('obfuscation_recovery_headers', fn (Blueprint $table) => $table->index(['source_epoch', 'groups_id', 'article_number'], 'recovery_header_article'));
        Schema::create('obfuscation_recovery_frontier_members', function (Blueprint $table): void {
            $table->unsignedBigInteger('bundle_id');
            $table->unsignedBigInteger('revision');
            $table->unsignedBigInteger('article_number');
            $table->dateTime('postdate');
            $table->char('observation_digest', 64);
            $table->unsignedBigInteger('embedded_timestamp_ms');
            $table->primary(['bundle_id', 'revision', 'article_number'], 'recovery_frontier_member');
        });
        Schema::table('obfuscation_recovery_scans', function (Blueprint $table): void {
            $table->unsignedSmallInteger('evidence_version')->default(1);
            $table->json('date_conflicts')->nullable();
            $table->json('invalid_date_articles')->nullable();
        });
        Schema::table('obfuscation_recovery_frontiers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('evidence_version')->default(1);
            $table->char('observation_digest', 64)->nullable();
        });
        Schema::table('obfuscation_recovery_frontier_conflicts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('evidence_version')->default(1);
            $table->index(['scope_digest', 'kind', 'last_article', 'first_article'], 'recovery_conflict_end');
            $expression = 'CASE';
            for ($bucket = 0; $bucket < 15; $bucket++) {
                $expression .= ' WHEN CAST(last_article AS SIGNED) - CAST(first_article AS SIGNED) < '.(1 << (4 * ($bucket + 1))).' THEN '.$bucket;
            }
            $table->unsignedTinyInteger('span_bucket')->virtualAs($expression.' ELSE 15 END');
            $table->index(['scope_digest', 'kind', 'span_bucket', 'first_article', 'last_article'], 'recovery_conflict_span');
        });
        Schema::create('obfuscation_recovery_frontier_ranges', function (Blueprint $table): void {
            $table->id();
            $table->char('identity', 64)->unique();
            $table->char('scope_digest', 64);
            $table->unsignedSmallInteger('evidence_version');
            $table->unsignedBigInteger('first_article');
            $table->unsignedBigInteger('last_article');
            $table->boolean('head_observed');
            $table->boolean('exhaustive');
            $table->json('points');
            $table->timestamp('observed_at', 6);
            $table->index(['scope_digest', 'evidence_version', 'first_article', 'last_article'], 'recovery_evidence_range');
        });
        Schema::create('obfuscation_recovery_frontier_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bundle_id')->unique();
            $table->char('budget_owner', 64);
            $table->unsignedInteger('groups_id');
            $table->string('source_epoch', 64);
            $table->unsignedBigInteger('capture_generation');
            $table->unsignedSmallInteger('evidence_version');
            $table->unsignedBigInteger('requested_first');
            $table->unsignedBigInteger('requested_last');
            $table->string('outcome', 48)->default('pending');
            $table->timestamp('expires_at', 6);
            $table->timestamps(6);
            $table->index(['budget_owner', 'capture_generation'], 'recovery_frontier_spend');
        });
        Schema::create('obfuscation_recovery_frontier_targets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('bundle_id');
            $table->unsignedBigInteger('revision');
            $table->char('plan_digest', 64)->nullable();
            $table->unsignedBigInteger('capture_generation');
            $table->unsignedBigInteger('first_article');
            $table->unsignedBigInteger('last_article');
            $table->json('envelope');
            $table->string('outcome', 48)->default('pending');
            $table->unique(['request_id', 'bundle_id', 'revision', 'first_article', 'last_article'], 'recovery_frontier_target');
            $table->index(['bundle_id', 'revision', 'outcome'], 'recovery_frontier_candidate');
        });
        Schema::create('obfuscation_recovery_frontier_progress', function (Blueprint $table): void {
            $table->string('scope', 64)->primary();
            $table->unsignedBigInteger('cursor')->default(0);
            $table->timestamp('due_at', 6)->nullable();
        });
        Schema::table('obfuscation_recovery_bundles', function (Blueprint $table): void {
            $table->index(['kind', 'state', 'id'], 'recovery_frontier_candidates');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('obfuscation_recovery_frontier_members');
        Schema::table('obfuscation_recovery_headers', fn (Blueprint $table) => $table->dropIndex('recovery_header_article'));
        Schema::dropIfExists('obfuscation_recovery_frontier_progress');
        Schema::dropIfExists('obfuscation_recovery_frontier_targets');
        Schema::dropIfExists('obfuscation_recovery_frontier_requests');
        Schema::dropIfExists('obfuscation_recovery_frontier_ranges');
        Schema::table('obfuscation_recovery_bundles', fn (Blueprint $table) => $table->dropIndex('recovery_frontier_candidates'));
        Schema::table('obfuscation_recovery_frontier_conflicts', function (Blueprint $table): void {
            $table->dropIndex('recovery_conflict_end');
            $table->dropIndex('recovery_conflict_span');
            $table->dropColumn(['evidence_version', 'span_bucket']);
        });
        Schema::table('obfuscation_recovery_frontiers', fn (Blueprint $table) => $table->dropColumn(['evidence_version', 'observation_digest']));
        Schema::table('obfuscation_recovery_scans', fn (Blueprint $table) => $table->dropColumn(['evidence_version', 'date_conflicts', 'invalid_date_articles']));
    }
};
