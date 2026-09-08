<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_traffic', function (Blueprint $table): void {
            $table->string('bucket', 100)->primary();
            $table->unsignedBigInteger('charged')->default(0);
            $table->unsignedBigInteger('actual')->default(0);
            $table->unsignedBigInteger('requests')->default(0);
        });
        Schema::create('reconciliation_evidence', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->longText('response');
            $table->timestamp('expires_at')->index();
        });
        Schema::create('reconciliation_claims', function (Blueprint $table): void {
            $table->unsignedBigInteger('collection_id')->primary();
            $table->string('owner', 36)->nullable();
            $table->string('revision', 64)->default('');
            $table->timestamp('deadline');
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('reason', 100)->default('pending');
            $table->unsignedBigInteger('release_id')->nullable()->index();
        });
        Schema::create('reconciled_postings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('release_id')->unique();
            if (Schema::hasTable('releases')) {
                $table->foreign('release_id')->references('id')->on('releases')->cascadeOnDelete();
            }
            $table->string('digest', 64);
            $table->string('source_digest', 64)->nullable();
            $table->string('budget_id', 64)->nullable();
            $table->string('review_digest', 64)->nullable()->unique();
            $table->string('state', 24);
            $table->boolean('independent_videos')->default(false);
            $table->longText('inventory');
            $table->longText('decision');
            $table->longText('original_nzb')->nullable();
            $table->longText('previous_journal')->nullable();
            $table->string('artifact_digest', 64)->nullable();
            $table->timestamps();
        });
        Schema::create('reconciled_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('posting_id')->constrained('reconciled_postings')->cascadeOnDelete();
            $table->string('collection_hash', 40);
            $table->unsignedInteger('group_id');
            $table->timestamp('postdate');
            $table->string('source_id', 100);
            $table->index(['collection_hash', 'group_id', 'postdate'], 'reconciled_source_lookup');
            $table->unique(['posting_id', 'source_id']);
        });
        Schema::create('reconciled_posting_inputs', function (Blueprint $table): void {
            $table->foreignId('posting_id')->constrained('reconciled_postings')->cascadeOnDelete();
            $table->unsignedInteger('release_id')->index();
            $table->primary(['posting_id', 'release_id']);
            if (Schema::hasTable('releases')) {
                $table->foreign('release_id')->references('id')->on('releases')->cascadeOnDelete();
            }
        });
        if (Schema::hasTable('collections')) {
            Schema::table('collections', function (Blueprint $table): void {
                $table->index(['groups_id', 'declaredfiles', 'date'], 'collections_reconciliation_discovery');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('collections')) {
            Schema::table('collections', fn (Blueprint $table) => $table->dropIndex('collections_reconciliation_discovery'));
        }
        foreach (['reconciled_posting_inputs', 'reconciled_sources', 'reconciled_postings', 'reconciliation_claims', 'reconciliation_evidence', 'reconciliation_traffic'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
