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
        Schema::table('reconciled_sources', function (Blueprint $table): void {
            $table->unsignedBigInteger('epoch')->default(1);
        });
        Schema::create('reconciled_artifacts', function (Blueprint $table): void {
            $table->unsignedInteger('release_id')->primary();
            $table->foreign('release_id', 'artifact_release_fk_'.substr(hash('sha256', DB::getTablePrefix()), 0, 12))->references('id')->on('releases')->cascadeOnDelete();
            $table->string('guid', 40);
            $table->unsignedBigInteger('version')->default(0);
            $table->unsignedBigInteger('epoch')->default(1);
            $table->unsignedBigInteger('proof_revision')->default(1);
            $table->longText('xml')->nullable();
            $table->string('digest', 64)->nullable();
            $table->uuid('pending_operation')->nullable()->unique();
            $table->boolean('search_pending')->default(false)->index();
            $table->boolean('cancelled')->default(false);
            $table->longText('provenance');
            $table->unsignedInteger('discovery_group_id')->nullable();
            $table->unsignedInteger('discovery_count')->nullable();
            $table->timestamp('discovery_postdate')->nullable();
            $table->string('discovery_poster')->nullable();
            $table->text('discovery_base')->nullable();
            $table->index(['discovery_group_id', 'discovery_count', 'discovery_postdate'], 'reconciled_artifact_discovery');
            $table->timestamps();
        });
        Schema::create('reconciled_artifact_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('release_id')->index();
            $table->foreign('release_id', 'operation_release_fk_'.substr(hash('sha256', DB::getTablePrefix()), 0, 12))->references('id')->on('releases')->cascadeOnDelete();
            $table->string('guid', 40);
            $table->string('kind', 32);
            $table->unsignedBigInteger('expected_version');
            $table->unsignedBigInteger('expected_epoch');
            $table->unsignedBigInteger('expected_proof_revision');
            $table->string('expected_digest', 64)->nullable();
            $table->string('target_digest', 64);
            $table->longText('target_xml')->nullable();
            $table->string('change_kind', 24);
            $table->longText('delta');
            $table->longText('updates');
            $table->longText('source_revisions');
            $table->longText('population')->nullable();
            $table->longText('ownership')->nullable();
            $table->longText('proof')->nullable();
            $table->string('state', 24)->default('prepared')->index();
            $table->uuid('worker')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->longText('result')->nullable();
            $table->timestamps();
        });
        Schema::create('reconciled_artifact_sources', function (Blueprint $table): void {
            $table->uuid('operation_id');
            $table->foreign('operation_id', 'artifact_source_fk_'.substr(hash('sha256', DB::getTablePrefix()), 0, 12))->references('id')->on('reconciled_artifact_operations')->cascadeOnDelete();
            $table->unsignedBigInteger('collection_id')->index();
            $table->string('revision', 64);
            $table->boolean('cleanup_pending')->default(true);
            $table->primary(['operation_id', 'collection_id']);
        });
        Schema::create('reconciled_proof_revisions', function (Blueprint $table): void {
            $table->unsignedInteger('release_id');
            $table->foreign('release_id', 'proof_release_fk_'.substr(hash('sha256', DB::getTablePrefix()), 0, 12))->references('id')->on('releases')->cascadeOnDelete();
            $table->unsignedBigInteger('revision');
            $table->unsignedBigInteger('epoch');
            $table->longText('inventory');
            $table->longText('decision');
            $table->primary(['release_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciled_artifact_sources');
        Schema::dropIfExists('reconciled_proof_revisions');
        Schema::dropIfExists('reconciled_artifact_operations');
        Schema::dropIfExists('reconciled_artifacts');
        Schema::table('reconciled_sources', fn (Blueprint $table) => $table->dropColumn('epoch'));
    }
};
