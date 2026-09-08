<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('par2_sidecar_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('target_id')->unique();
            $table->unsignedInteger('source_id')->unique();
            $table->string('target_guid', 40);
            $table->string('source_guid', 40);
            $table->char('leftguid', 1);
            $table->string('phase', 24)->default('selected');
            $table->string('reason', 100)->nullable();
            $table->boolean('absorb');
            $table->text('filename');
            $table->char('target_fingerprint', 64);
            $table->char('source_fingerprint', 64);
            $table->char('combined_fingerprint', 64)->nullable();
            $table->longText('target_xml');
            $table->longText('source_xml');
            $table->json('accounting');
            $table->json('descriptors');
            $table->json('hashes');
            $table->json('named_state')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamps();
            $table->index(['phase', 'retry_at', 'id'], 'sidecar_operation_work');
            $table->index(['leftguid', 'phase', 'retry_at', 'id'], 'sidecar_operation_bucket');
            $table->index(['target_id', 'phase']);
        });
        Schema::create('payload_prefix_hashes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('nzb_file_index');
            $table->text('first_message_id');
            $table->char('leftguid', 1);
            $table->char('prefix_hash', 32);
            $table->unsignedBigInteger('raw_size');
            $table->unsignedBigInteger('decoded_length');
            $table->unsignedInteger('segment_number');
            $table->unsignedBigInteger('segment_offset');
            $table->unsignedInteger('observed_segments');
            $table->unsignedInteger('declared_segments');
            $table->json('segment_numbers');
            $table->char('fingerprint', 64);
            $table->timestamp('captured_at');
            $table->timestamp('evaluated_at')->nullable();
            $table->string('state', 24)->default('pending');
            $table->string('reason', 100)->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->foreignId('operation_id')->nullable()->constrained('par2_sidecar_operations');
            $table->unique(['releases_id', 'nzb_file_index']);
            $table->index(['prefix_hash', 'raw_size']);
            $table->index(['state', 'retry_at', 'id'], 'sidecar_prefix_work');
            $table->index(['leftguid', 'state', 'retry_at', 'id'], 'sidecar_prefix_bucket');
            $table->index(['state', 'captured_at'], 'sidecar_prefix_expiry');
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
        Schema::create('par2_file_descriptors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('releases_id');
            $table->char('set_id', 32);
            $table->char('file_id', 32);
            $table->char('hash16k', 32);
            $table->char('full_hash', 32)->nullable();
            $table->unsignedBigInteger('raw_size');
            $table->text('filename');
            $table->boolean('naming_ambiguous')->default(false);
            $table->char('identity', 64);
            $table->char('fingerprint', 64);
            $table->unsignedInteger('origin_release_id');
            $table->timestamp('captured_at');
            $table->unique(['releases_id', 'identity']);
            $table->index(['hash16k', 'raw_size']);
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
        Schema::create('par2_sidecar_inventories', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->char('fingerprint', 64);
            $table->unsignedInteger('total_files');
            $table->json('files');
            $table->boolean('naming_ambiguous')->default(false);
            $table->boolean('pure');
            $table->boolean('complete');
            $table->string('reason', 100)->nullable();
            $table->timestamp('captured_at');
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('par2_sidecar_inventories');
        Schema::dropIfExists('par2_file_descriptors');
        Schema::dropIfExists('payload_prefix_hashes');
        Schema::dropIfExists('par2_sidecar_operations');
    }
};
