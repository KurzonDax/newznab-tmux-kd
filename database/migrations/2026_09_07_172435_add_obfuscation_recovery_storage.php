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
        if (Schema::hasTable('releases') && ! Schema::hasColumn('releases', 'recovery_claim_token')) {
            Schema::table('releases', fn (Blueprint $table) => $table->uuid('recovery_claim_token')->nullable());
        }
        Schema::table('usenet_groups', function (Blueprint $table): void {
            $table->string('obfuscation_recovery_profile', 16)->default('disabled');
        });

        Schema::create('obfuscation_recovery_controls', function (Blueprint $table): void {
            $table->string('scope', 64)->primary();
            $table->char('fingerprint', 64);
            $table->uuid('epoch');
            $table->unsignedBigInteger('generation')->default(1);
            $table->timestamp('updated_at', 6);
        });
        Schema::create('obfuscation_recovery_housekeeping', function (Blueprint $table): void {
            $table->string('scope', 32)->primary();
            $table->string('after_key', 64)->nullable();
        });
        Schema::create('obfuscation_recovery_headers', function (Blueprint $table): void {
            $table->id();
            $table->string('source_epoch', 64);
            $table->unsignedInteger('groups_id');
            $table->unsignedBigInteger('capture_generation');
            $table->string('message_id', 255)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin');
            $table->binary('source_message_id');
            $table->char('message_id_digest', 64);
            $table->unsignedBigInteger('article_number');
            $table->binary('raw_subject');
            $table->binary('parsed_name')->nullable();
            $table->binary('poster_identity');
            $table->string('source_date', 128);
            $table->dateTime('postdate');
            $table->binary('xref')->nullable();
            $table->unsignedBigInteger('advertised_bytes');
            $table->unsignedInteger('original_part')->nullable();
            $table->unsignedInteger('advertised_total')->nullable();
            $table->unsignedBigInteger('embedded_timestamp_ms');
            $table->string('profile', 40);
            $table->char('key_digest', 64);
            $table->string('disposition', 48)->default('eligible');
            $table->boolean('metadata_conflict')->default(false);
            $table->unsignedBigInteger('bundle_id')->nullable();
            $table->unsignedBigInteger('revision')->nullable();
            $table->char('capture_token', 32)->nullable();
            $table->timestamp('first_observed_at', 6);
            $table->timestamp('last_observed_at', 6);
            $table->unique(['source_epoch', 'groups_id', 'message_id_digest'], 'recovery_header_identity');
            $table->index(['source_epoch', 'groups_id', 'advertised_total', 'embedded_timestamp_ms', 'message_id'], 'recovery_media_discovery');
            $table->index(['source_epoch', 'groups_id', 'key_digest', 'embedded_timestamp_ms', 'message_id'], 'recovery_rar_discovery');
            $table->index(['first_observed_at', 'id'], 'recovery_header_expiry');
            $table->index(['bundle_id', 'revision', 'id'], 'recovery_header_membership');
        });
        Schema::create('obfuscation_recovery_expired_headers', function (Blueprint $table): void {
            $table->string('source_epoch', 64);
            $table->unsignedInteger('groups_id');
            $table->char('message_id_digest', 64);
            $table->timestamp('first_observed_at', 6);
            $table->primary(['source_epoch', 'groups_id', 'message_id_digest'], 'recovery_expired_identity');
        });
        Schema::create('obfuscation_recovery_dirty', function (Blueprint $table): void {
            $table->id();
            $table->char('scope_digest', 64)->unique();
            $table->string('source_epoch', 64);
            $table->unsignedInteger('groups_id');
            $table->unsignedBigInteger('capture_generation');
            $table->string('profile', 40);
            $table->string('partition_value', 64);
            $table->unsignedBigInteger('first_ms');
            $table->unsignedBigInteger('last_ms');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamp('membership_changed_at', 6);
            $table->timestamp('next_action_at', 6);
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claim_expires_at', 6)->nullable();
            $table->index(['next_action_at', 'id'], 'recovery_dirty_due');
        });
        Schema::create('obfuscation_recovery_scan_batches', function (Blueprint $table): void {
            $table->uuid('scan_id')->primary();
            $table->char('context_digest', 64);
            $table->timestamp('created_at', 6);
            $table->index('created_at', 'recovery_batch_expiry');
        });
        Schema::create('obfuscation_recovery_scan_windows', function (Blueprint $table): void {
            $table->uuid('scan_id')->primary();
            $table->unsignedInteger('groups_id');
            $table->string('source_epoch', 64);
            $table->unsignedBigInteger('capture_generation');
            $table->unsignedBigInteger('requested_first');
            $table->unsignedBigInteger('requested_last');
            $table->unsignedBigInteger('gap_cursor')->nullable();
            $table->unsignedBigInteger('frontier_last')->nullable();
            $table->timestamp('next_gap_at', 6)->nullable();
            $table->timestamp('expires_at', 6);
            $table->timestamp('created_at', 6);
            $table->index(['next_gap_at', 'scan_id'], 'recovery_window_due');
            $table->index(['groups_id', 'source_epoch', 'capture_generation', 'requested_first'], 'recovery_window_scope');
        });
        Schema::create('obfuscation_recovery_gaps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bundle_id')->unique();
            $table->unsignedInteger('groups_id');
            $table->string('source_epoch', 64);
            $table->unsignedBigInteger('capture_generation');
            $table->unsignedBigInteger('requested_first');
            $table->unsignedBigInteger('requested_last');
            $table->string('outcome', 48)->default('pending');
            $table->timestamp('expires_at', 6);
            $table->timestamps(6);
            $table->unique(['groups_id', 'source_epoch', 'capture_generation', 'requested_first', 'requested_last'], 'recovery_gap_range');
            $table->index(['groups_id', 'source_epoch', 'capture_generation', 'requested_first'], 'recovery_gap_overlap');
        });
        Schema::create('obfuscation_recovery_scans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('scan_id');
            $table->unsignedInteger('groups_id');
            $table->string('source_epoch', 64);
            $table->unsignedBigInteger('capture_generation');
            $table->unsignedBigInteger('requested_first');
            $table->unsignedBigInteger('requested_last');
            $table->unsignedInteger('chunk_ordinal');
            $table->unsignedInteger('expected_chunks');
            $table->string('direction', 16);
            $table->string('capture_outcome', 32);
            $table->string('ordinary_outcome', 32)->default('unknown');
            $table->json('ordinary_report')->nullable();
            $table->timestamp('coverage_verified_at', 6)->nullable();
            $table->timestamp('compacted_at', 6)->nullable();
            $table->unsignedBigInteger('returned_articles')->nullable();
            $table->unsignedBigInteger('missing_articles')->nullable();
            $table->json('returned_ranges')->nullable();
            $table->json('missing_ranges')->nullable();
            $table->json('date_points')->nullable();
            $table->dateTime('first_postdate')->nullable();
            $table->dateTime('last_postdate')->nullable();
            $table->unsignedBigInteger('earliest_date_article')->nullable();
            $table->unsignedBigInteger('latest_date_article')->nullable();
            $table->boolean('date_order_consistent')->default(true);
            $table->boolean('complete')->default(false);
            $table->timestamp('created_at', 6);
            $table->unique(['scan_id', 'chunk_ordinal'], 'recovery_scan_chunk');
            $table->index(['source_epoch', 'groups_id', 'capture_generation', 'requested_first', 'requested_last'], 'recovery_scan_coverage');
            $table->index(['created_at', 'id'], 'recovery_scan_expiry');
            $table->index(['compacted_at', 'created_at', 'id'], 'recovery_scan_compaction');
        });
        Schema::create('obfuscation_recovery_frontiers', function (Blueprint $table): void {
            $table->char('scope_digest', 64);
            $table->unsignedBigInteger('article_number');
            $table->dateTime('postdate');
            $table->boolean('head_observed');
            $table->primary(['scope_digest', 'article_number'], 'recovery_frontier_point');
            $table->index(['scope_digest', 'head_observed', 'postdate', 'article_number'], 'recovery_frontier_lookup');
        });
        Schema::create('obfuscation_recovery_frontier_conflicts', function (Blueprint $table): void {
            $table->char('identity', 64)->primary();
            $table->char('scope_digest', 64);
            $table->string('kind', 16);
            $table->unsignedBigInteger('first_article');
            $table->unsignedBigInteger('last_article');
            $table->index(['scope_digest', 'kind', 'first_article', 'last_article'], 'recovery_frontier_conflict_range');
        });
        Schema::create('obfuscation_recovery_catalog', function (Blueprint $table): void {
            $table->id();
            $table->char('aggregate_digest', 64)->nullable()->unique();
            $table->unsignedBigInteger('requests')->default(1);
            $table->unsignedBigInteger('unknown_response_sizes')->default(0);
            $table->unsignedBigInteger('publication_id');
            $table->string('provider', 255);
            $table->string('kind', 16);
            $table->string('outcome', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedBigInteger('response_bytes')->nullable();
            $table->timestamp('created_at', 6);
            $table->timestamp('finished_at', 6)->nullable();
            $table->index(['publication_id', 'created_at'], 'recovery_catalog_publication');
            $table->index(['aggregate_digest', 'created_at', 'id'], 'recovery_catalog_compaction');
        });
        Schema::create('obfuscation_recovery_coverage', function (Blueprint $table): void {
            $table->id();
            $table->char('scope_digest', 64);
            $table->string('source_epoch', 64);
            $table->unsignedInteger('groups_id');
            $table->unsignedBigInteger('capture_generation');
            $table->string('kind', 16);
            $table->string('direction', 16);
            $table->unsignedBigInteger('first_article');
            $table->unsignedBigInteger('last_article');
            $table->index(['scope_digest', 'kind', 'direction', 'first_article', 'last_article'], 'recovery_positive_coverage');
            $table->index(['source_epoch', 'groups_id', 'capture_generation', 'kind', 'first_article'], 'recovery_positive_scope');
        });
        Schema::create('obfuscation_recovery_runs', function (Blueprint $table): void {
            $table->id();
            $table->char('run_identity', 64)->unique();
            $table->char('scope_digest', 64);
            $table->string('source_epoch', 64);
            $table->unsignedInteger('groups_id');
            $table->unsignedBigInteger('capture_generation');
            $table->string('profile', 40);
            $table->string('partition_value', 64);
            $table->unsignedBigInteger('start_ms');
            $table->unsignedBigInteger('end_ms');
            $table->unsignedInteger('observed_count');
            $table->string('state', 32);
            $table->char('membership_digest', 64);
            $table->json('summary');
            $table->boolean('active')->default(true);
            $table->boolean('bundle_dirty')->default(true);
            $table->timestamp('oldest_observed_at', 6);
            $table->timestamps(6);
            $table->index(['scope_digest', 'active', 'start_ms'], 'recovery_run_scope');
            $table->index(['source_epoch', 'groups_id', 'capture_generation', 'profile', 'active', 'start_ms'], 'recovery_run_components');
            $table->index(['bundle_dirty', 'updated_at', 'id'], 'recovery_run_due');
            $table->index(['oldest_observed_at', 'id'], 'recovery_run_expiry');
        });
        Schema::create('obfuscation_recovery_files', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bundle_id');
            $table->unsignedBigInteger('revision');
            $table->unsignedInteger('groups_id');
            $table->string('profile', 40);
            $table->char('run_digest', 64);
            $table->unsignedInteger('advertised_total')->nullable();
            $table->unsignedInteger('expected_total')->nullable();
            $table->unsignedInteger('observed_count');
            $table->unsignedBigInteger('start_ms');
            $table->unsignedBigInteger('end_ms');
            $table->unsignedInteger('boundary_first')->nullable();
            $table->unsignedInteger('boundary_last')->nullable();
            $table->string('state', 32);
            $table->string('reason', 64)->nullable();
            $table->string('role', 16)->nullable();
            $table->char('membership_digest', 64)->nullable();
            $table->char('file_id', 32)->nullable();
            $table->unsignedBigInteger('decoded_bytes')->nullable();
            $table->char('file_md5', 32)->nullable();
            $table->char('prefix_md5', 32)->nullable();
            $table->binary('source_filename')->nullable();
            $table->string('display_filename', 240)->nullable();
            $table->string('declared_format', 24)->nullable();
            $table->string('observed_format', 24)->nullable();
            $table->unsignedTinyInteger('archive_ordinal')->nullable();
            $table->json('anchor_evidence')->nullable();
            $table->json('terminal_evidence')->nullable();
            $table->json('contained_observations')->nullable();
            $table->json('identity_evidence')->nullable();
            $table->json('media_evidence')->nullable();
            $table->string('enrichment_outcome', 48)->nullable();
            $table->string('enrichment_reason', 48)->nullable();
            $table->unsignedBigInteger('enrichment_debit')->default(0);
            $table->timestamp('enrichment_selected_at')->nullable();
            $table->timestamp('detail_retired_at')->nullable();
            $table->timestamps(6);
            $table->unique(['bundle_id', 'revision', 'run_digest'], 'recovery_file_revision');
            $table->index(['bundle_id', 'detail_retired_at', 'id'], 'recovery_file_retirement');
            $table->index(['groups_id', 'profile', 'advertised_total', 'start_ms'], 'recovery_file_discovery');
        });

        Schema::create('obfuscation_recovery_publications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('canonical_bundle_id')->nullable()->index();
            $table->unsignedBigInteger('canonical_revision')->nullable();
            $table->char('identity', 64)->unique();
            $table->char('index_identity', 64)->unique();
            $table->string('index_message_id', 255);
            $table->char('set_id', 32);
            $table->char('plan_digest', 64);
            $table->binary('collection_projection', 20, true)->unique();
            $table->unsignedInteger('collections_id')->nullable()->unique();
            $table->unsignedInteger('releases_id')->nullable()->index();
            $table->string('guid', 40)->nullable();
            $table->string('profile', 40);
            $table->string('group_name', 255);
            $table->string('source_epoch', 64);
            $table->string('state', 32)->default('registered');
            $table->string('initialization_state', 32)->default('pending');
            $table->string('ordering_mode', 48);
            $table->string('inventory_scope', 48);
            $table->unsignedInteger('protected_files');
            $table->unsignedInteger('planned_files');
            $table->unsignedBigInteger('planned_parts');
            $table->unsignedBigInteger('materialized_parts')->default(0);
            $table->unsignedBigInteger('reconciliation_cursor')->default(0);
            $table->string('cleanup_outcome', 48)->nullable();
            $table->json('sealed_plan');
            $table->char('manifest_digest', 64);
            $table->char('nzb_digest', 64)->nullable();
            $table->string('survivor_membership', 48)->nullable();
            $table->char('survivor_nzb_digest', 64)->nullable();
            $table->char('evidence_digest', 64)->nullable();
            $table->json('head_membership')->nullable();
            $table->timestamp('enrichment_next_attempt_at')->nullable();
            $table->string('identity_outcome', 48)->default('unresolved');
            $table->string('nfo_outcome', 48)->nullable();
            $table->string('identity_scope', 48)->default('unknown');
            $table->string('enrichment_outcome', 48)->nullable();
            $table->boolean('multi_media_inventory')->default(false);
            $table->string('reason', 64)->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('detail_retired_at')->nullable();
            $table->timestamps(6);
            $table->index(['state', 'id'], 'recovery_publication_state');
        });

        Schema::create('obfuscation_recovery_targets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('publication_id');
            $table->char('file_id', 32);
            $table->string('message_id', 255);
            $table->char('request_digest', 64);
            $table->unsignedTinyInteger('ordinal');
            $table->string('status', 24)->default('pending');
            $table->string('outcome', 48)->nullable();
            $table->timestamps(6);
            $table->unique(['publication_id', 'request_digest'], 'recovery_target_identity');
            $table->index(['publication_id', 'file_id'], 'recovery_target_file');
            $table->index(['outcome', 'status', 'updated_at', 'id'], 'recovery_target_resume');
        });

        Schema::create('obfuscation_recovery_bundles', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('detail_retired_at')->nullable();
            $table->timestamp('inactive_since', 6)->nullable();
            $table->char('owner_digest', 64)->unique();
            $table->string('kind', 16)->default('posting');
            $table->unsignedBigInteger('publication_id')->nullable()->index();
            $table->string('profile', 40)->nullable();
            $table->unsignedInteger('groups_id')->nullable();
            $table->uuid('source_epoch')->nullable();
            $table->unsignedBigInteger('capture_generation')->nullable();
            $table->char('key_digest', 64)->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('start_ms')->nullable();
            $table->unsignedBigInteger('end_ms')->nullable();
            $table->timestamp('membership_changed_at', 6)->nullable();
            $table->string('state', 32)->default('collecting');
            $table->string('reason', 64)->nullable();
            $table->char('snapshot_digest', 64)->nullable();
            $table->json('candidate_runs')->nullable();
            $table->unsignedBigInteger('merged_into')->nullable()->index();
            $table->string('index_message_id', 255)->nullable();
            $table->char('index_digest', 64)->nullable();
            $table->json('inventory')->nullable();
            $table->json('construction_targets')->nullable();
            $table->json('sealed_plan')->nullable();
            $table->timestamp('manifest_verified_at', 6)->nullable();
            $table->json('coverage_evidence')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claim_expires_at', 6)->nullable();
            $table->timestamp('next_action_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['state', 'next_action_at', 'id'], 'recovery_bundle_due');
            $table->index(['groups_id', 'profile', 'start_ms'], 'recovery_bundle_window');
        });
        Schema::create('obfuscation_recovery_provider_backoff', function (Blueprint $table): void {
            $table->char('provider_digest', 64)->primary();
            $table->timestamp('blocked_until', 6);
            $table->string('reason', 64);
            $table->timestamp('updated_at', 6);
        });
        Schema::create('obfuscation_recovery_dispatch', function (Blueprint $table): void {
            $table->char('scope_digest', 64)->primary();
            $table->string('stage', 24);
            $table->timestamp('last_dispatched_at', 6)->nullable();
            $table->index(['stage', 'last_dispatched_at', 'scope_digest'], 'recovery_dispatch_order');
        });
        Schema::create('obfuscation_recovery_work', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bundle_id');
            $table->unsignedBigInteger('releases_id')->nullable();
            $table->unsignedBigInteger('revision');
            $table->string('stage', 24);
            $table->string('purpose', 40);
            $table->char('dispatch_scope', 64);
            $table->char('request_digest', 64);
            $table->json('payload');
            $table->string('status', 24)->default('pending');
            $table->string('result', 48)->nullable();
            $table->timestamp('due_at', 6);
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claim_expires_at', 6)->nullable();
            $table->timestamp('reclaim_after', 6)->nullable();
            $table->char('claim_owner_host', 64)->nullable();
            $table->unsignedInteger('claim_owner_pid')->nullable();
            $table->string('claim_owner_started', 32)->nullable();
            $table->timestamps(6);
            $table->unique(['request_digest', 'revision'], 'recovery_logical_request');
            $table->index(['stage', 'status', 'due_at', 'id'], 'recovery_work_due');
            $table->index(['bundle_id', 'revision'], 'recovery_work_revision');
            $table->index(['dispatch_scope', 'status', 'due_at', 'id'], 'recovery_scope_due');
            $table->index(['status', 'claim_expires_at', 'id'], 'recovery_work_expired');
            $table->index(['status', 'reclaim_after', 'id'], 'recovery_work_reclaim');
        });

        Schema::create('obfuscation_recovery_metrics', function (Blueprint $table): void {
            $table->char('series_digest', 64)->primary();
            $table->unsignedInteger('groups_id');
            $table->string('profile', 40);
            $table->string('reason', 64);
            $table->string('metric', 40);
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamp('updated_at', 6);
        });
        Schema::create('obfuscation_recovery_artifacts', function (Blueprint $table): void {
            $table->char('digest', 64)->primary();
            $table->unsignedBigInteger('bytes');
            $table->timestamp('retained_at', 6)->index();
        });
        Schema::create('obfuscation_recovery_references', function (Blueprint $table): void {
            $table->char('identity', 64)->primary();
            $table->string('owner_type', 16);
            $table->string('owner_key', 64);
            $table->string('resource_type', 16);
            $table->char('resource_digest', 64);
            $table->index(['owner_type', 'owner_key'], 'recovery_reference_owner');
            $table->index(['resource_type', 'resource_digest'], 'recovery_reference_resource');
        });
        Schema::create('obfuscation_recovery_evidence', function (Blueprint $table): void {
            $table->char('message_id_digest', 64)->primary();
            $table->string('message_id', 255)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin');
            $table->json('prefix_evidence')->nullable();
            $table->json('full_evidence')->nullable();
            $table->json('fingerprints')->nullable();
            $table->string('state', 24)->default('valid');
            $table->timestamps(6);
            $table->index(['created_at', 'message_id_digest'], 'recovery_evidence_expiry');
        });
        Schema::create('obfuscation_recovery_slots', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('worker_token')->nullable()->unique();
            $table->char('owner_host', 64)->nullable();
            $table->unsignedInteger('owner_pid')->nullable();
            $table->string('owner_started', 32)->nullable();
            $table->timestamp('acquired_at', 6)->nullable();
            $table->timestamp('expires_at', 6)->nullable();
            $table->unsignedBigInteger('attempt_id')->nullable();
            $table->unique(['owner_host', 'owner_pid', 'owner_started'], 'recovery_one_slot_per_worker');
        });
        DB::table('obfuscation_recovery_slots')->insert(array_map(static fn (int $id): array => ['id' => $id], range(1, 60)));
        Schema::create('obfuscation_recovery_index_owners', function (Blueprint $table): void {
            $table->char('index_digest', 64)->primary();
            $table->char('owner_digest', 64);
            $table->json('construction_targets');
            $table->timestamps(6);
        });
        Schema::create('obfuscation_recovery_budget_owners', function (Blueprint $table): void {
            $table->char('owner_digest', 64)->primary();
            $table->char('root_digest', 64)->index();
            $table->timestamps(6);
        });
        Schema::create('obfuscation_recovery_budgets', function (Blueprint $table): void {
            $table->id();
            $table->char('owner_digest', 64)->charset('ascii')->collation(DB::connection()->getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin');
            $table->string('purpose', 32);
            $table->unsignedBigInteger('debited_bytes')->default(0);
            $table->timestamps();
            $table->unique(['owner_digest', 'purpose'], 'recovery_budget_owner');
        });
        Schema::create('obfuscation_recovery_traffic', function (Blueprint $table): void {
            $table->char('series_digest', 64)->primary();
            $table->date('day');
            $table->unsignedInteger('groups_id');
            $table->string('profile', 40);
            $table->string('purpose', 32);
            $table->string('provider', 64);
            $table->string('outcome', 64);
            $table->string('failure_phase', 64);
            foreach (['attempts', 'unknown_transport_counters', 'reserved_bytes', 'debited_bytes', 'decoded_bytes', 'plaintext_bytes',
                'encrypted_bytes', 'socket_received_bytes', 'transmitted_bytes', 'connections_opened', 'elapsed_milliseconds', 'late_receive_allowance'] as $counter) {
                $table->unsignedBigInteger($counter)->default(0);
            }
            $table->index(['groups_id', 'profile', 'day'], 'recovery_traffic_scope');
        });
        Schema::create('obfuscation_recovery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('budget_id');
            $table->unsignedInteger('groups_id')->default(0);
            $table->string('profile', 40)->default('unknown');
            $table->char('request_digest', 64)->charset('ascii')->collation(DB::connection()->getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin');
            $table->unsignedTinyInteger('physical_attempt');
            $table->uuid('token')->unique();
            $table->string('stage', 24)->default('download');
            $table->string('provider', 64)->nullable();
            $table->string('outcome', 64)->default('reserved');
            $table->unsignedBigInteger('reserved_bytes');
            $table->unsignedBigInteger('debited_bytes');
            $table->unsignedBigInteger('decoded_bytes')->nullable();
            $table->unsignedBigInteger('plaintext_bytes')->nullable();
            $table->unsignedBigInteger('encrypted_bytes')->nullable();
            $table->unsignedBigInteger('socket_received_bytes')->nullable();
            $table->unsignedBigInteger('transmitted_bytes')->nullable();
            $table->unsignedInteger('connections_opened')->default(0);
            $table->unsignedInteger('receive_window')->nullable();
            $table->unsignedInteger('buffered_at_close')->nullable();
            $table->unsignedInteger('elapsed_milliseconds')->nullable();
            $table->unsignedBigInteger('late_receive_allowance')->default(0);
            $table->boolean('cache_hit')->default(false);
            $table->timestamp('connected_at', 6)->nullable();
            $table->timestamp('closed_at', 6)->nullable();
            $table->timestamp('settled_at', 6)->nullable();
            $table->string('failure_phase', 64)->nullable();
            $table->timestamp('compacted_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['budget_id', 'request_digest', 'physical_attempt'], 'recovery_physical_attempt');
            $table->index(['outcome', 'created_at'], 'recovery_attempt_expiry');
            $table->index(['compacted_at', 'created_at', 'id'], 'recovery_attempt_compaction');
        });

        foreach ([
            'obfuscation_recovery_enabled' => '0',
            'obfuscation_recovery_threads' => '2',
            'obfuscation_recovery_media_candidate_mib' => '20',
            'obfuscation_recovery_rar_candidate_mib' => '40',
            'obfuscation_recovery_retention_hours' => '144',
            'obfuscation_recovery_enrichment_enabled' => '1',
            'obfuscation_recovery_enrichment_release_mib' => '4',
        ] as $name => $value) {
            DB::table('settings')->insertOrIgnore(compact('name', 'value'));
        }
    }

    public function down(): void
    {
        if (DB::table('obfuscation_recovery_attempts')->exists() || DB::table('obfuscation_recovery_bundles')->exists() || DB::table('obfuscation_recovery_publications')->exists() || DB::table('obfuscation_recovery_headers')->exists()) {
            throw new RuntimeException('Retained recovery evidence must be archived before schema rollback.');
        }
        if (Schema::hasColumn('releases', 'recovery_claim_token')) {
            Schema::table('releases', fn (Blueprint $table) => $table->dropColumn('recovery_claim_token'));
        }
        Schema::dropIfExists('obfuscation_recovery_headers');
        Schema::dropIfExists('obfuscation_recovery_expired_headers');
        Schema::dropIfExists('obfuscation_recovery_scans');
        Schema::dropIfExists('obfuscation_recovery_coverage');
        Schema::dropIfExists('obfuscation_recovery_catalog');
        Schema::dropIfExists('obfuscation_recovery_frontiers');
        Schema::dropIfExists('obfuscation_recovery_frontier_conflicts');
        Schema::dropIfExists('obfuscation_recovery_scan_windows');
        Schema::dropIfExists('obfuscation_recovery_gaps');
        Schema::dropIfExists('obfuscation_recovery_scan_batches');
        Schema::dropIfExists('obfuscation_recovery_controls');
        Schema::dropIfExists('obfuscation_recovery_housekeeping');
        Schema::dropIfExists('obfuscation_recovery_targets');
        Schema::dropIfExists('obfuscation_recovery_files');
        Schema::dropIfExists('obfuscation_recovery_publications');
        Schema::dropIfExists('obfuscation_recovery_work');
        Schema::dropIfExists('obfuscation_recovery_dispatch');
        Schema::dropIfExists('obfuscation_recovery_provider_backoff');
        Schema::dropIfExists('obfuscation_recovery_bundles');
        Schema::dropIfExists('obfuscation_recovery_attempts');
        Schema::dropIfExists('obfuscation_recovery_traffic');
        Schema::dropIfExists('obfuscation_recovery_budgets');
        Schema::dropIfExists('obfuscation_recovery_budget_owners');
        Schema::dropIfExists('obfuscation_recovery_index_owners');
        Schema::dropIfExists('obfuscation_recovery_slots');
        Schema::dropIfExists('obfuscation_recovery_evidence');
        Schema::dropIfExists('obfuscation_recovery_references');
        Schema::dropIfExists('obfuscation_recovery_artifacts');
        Schema::dropIfExists('obfuscation_recovery_dirty');
        Schema::dropIfExists('obfuscation_recovery_metrics');
        Schema::dropIfExists('obfuscation_recovery_runs');
        Schema::table('usenet_groups', function (Blueprint $table): void {
            $table->dropColumn('obfuscation_recovery_profile');
        });
    }
};
