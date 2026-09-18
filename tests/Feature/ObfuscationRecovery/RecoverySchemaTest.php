<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ObfuscationRecovery\ChecksFrontierMigration;
use Tests\TestCase;

final class RecoverySchemaTest extends TestCase
{
    use ChecksFrontierMigration;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        (require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php'))->up();
        (require database_path('migrations/2026_09_13_190549_add_recovery_frontier_request_attribution.php'))->up();
        (require database_path('migrations/2026_09_14_110835_add_recovery_handoff_and_process_identity.php'))->up();
    }

    /** The table-prefix test rebuilds these production tables under the fixture_ prefix. */
    protected function fixtureOnlyTables(): array
    {
        return array_map(static fn (string $table): string => 'fixture_'.$table, [
            'settings', 'usenet_groups', 'obfuscation_recovery_artifacts', 'obfuscation_recovery_attempts',
            'obfuscation_recovery_budget_owners', 'obfuscation_recovery_budgets', 'obfuscation_recovery_bundles',
            'obfuscation_recovery_catalog', 'obfuscation_recovery_controls', 'obfuscation_recovery_coverage',
            'obfuscation_recovery_dirty', 'obfuscation_recovery_dispatch', 'obfuscation_recovery_evidence',
            'obfuscation_recovery_expired_headers', 'obfuscation_recovery_files',
            'obfuscation_recovery_frontier_allowances', 'obfuscation_recovery_frontier_conflicts',
            'obfuscation_recovery_frontier_installs', 'obfuscation_recovery_frontier_members',
            'obfuscation_recovery_frontier_policy', 'obfuscation_recovery_frontier_progress',
            'obfuscation_recovery_frontier_ranges', 'obfuscation_recovery_frontier_requests',
            'obfuscation_recovery_frontier_targets', 'obfuscation_recovery_frontiers', 'obfuscation_recovery_gaps',
            'obfuscation_recovery_headers', 'obfuscation_recovery_housekeeping', 'obfuscation_recovery_index_owners',
            'obfuscation_recovery_metrics', 'obfuscation_recovery_provider_backoff',
            'obfuscation_recovery_publications', 'obfuscation_recovery_references', 'obfuscation_recovery_runs',
            'obfuscation_recovery_scan_batches', 'obfuscation_recovery_scan_windows', 'obfuscation_recovery_scans',
            'obfuscation_recovery_slots', 'obfuscation_recovery_targets', 'obfuscation_recovery_traffic',
            'obfuscation_recovery_work',
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_frontier_migration_supports_sqlite_table_prefixes(): void
    {
        Schema::dropAllTables();
        DB::connection()->setTablePrefix('fixture_');
        try {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
            Schema::create('usenet_groups', fn (Blueprint $table) => $table->increments('id'));
            (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
            (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
            (require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php'))->up();
            (require database_path('migrations/2026_09_13_190549_add_recovery_frontier_request_attribution.php'))->up();
            (require database_path('migrations/2026_09_14_110835_add_recovery_handoff_and_process_identity.php'))->up();
            $this->test_additive_frontier_migration_preserves_legacy_facts_until_verified_replacement();
        } finally {
            DB::connection()->setTablePrefix('');
        }
    }

    public function test_raw_storage_preserves_large_article_numbers_and_absent_source_counters(): void
    {
        DB::table('obfuscation_recovery_headers')->insert([
            'source_epoch' => 'epoch', 'groups_id' => 1, 'capture_generation' => 1,
            'message_id' => 'Case@example.invalid', 'source_message_id' => '<Case@example.invalid>',
            'message_id_digest' => hash('sha256', 'Case@example.invalid'),
            'article_number' => 4000000001, 'raw_subject' => 'opaque', 'poster_identity' => 'fixture@example.invalid',
            'source_date' => 'Tue, 14 Nov 2023 22:13:20 +0000', 'postdate' => '2023-11-14 22:13:20',
            'key_digest' => str_repeat('a', 64), 'profile' => 'nyuu-rar-sequential-v1',
            'advertised_bytes' => 123, 'embedded_timestamp_ms' => 1700000000000,
            'first_observed_at' => now(), 'last_observed_at' => now(),
        ]);
        $header = DB::table('obfuscation_recovery_headers')->first();
        $this->assertSame(4000000001, $header->article_number);
        $this->assertSame(1700000000000, $header->embedded_timestamp_ms);
        $this->assertNull($header->original_part);
        $this->assertNull($header->advertised_total);
        $this->assertSame('Case@example.invalid', $header->message_id);
        $this->assertFalse(Schema::hasColumn('obfuscation_recovery_headers', 'collections_id'));
    }
}
