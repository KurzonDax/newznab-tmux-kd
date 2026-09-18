<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class SchemaGuardLifecycleFixture extends TestCase
{
    use IsolatedSqliteDatabase;

    private const string RELEASES_MIGRATION = '2026_08_13_001652_normalize_and_optimize_releases_table.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        if ($this->name() === 'test_checks_tables_built_before_a_skip') {
            DB::statement('CREATE TABLE video_data (id INTEGER PRIMARY KEY, releases_id INTEGER)');
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    protected function fixtureOnlyTables(): array
    {
        return ['declared_probe', 'renamed_probe'];
    }

    protected function historicalSchema(): ?array
    {
        return match ($this->name()) {
            'test_skips_key_rules_for_declared_historical_tables', 'test_rejects_a_historical_table_that_is_never_built' => ['migration' => self::RELEASES_MIGRATION, 'tables' => ['releases']],
            'test_rejects_a_historical_migration_that_does_not_exist' => ['migration' => '2026_01_01_000000_missing_migration.php', 'tables' => ['releases']],
            'test_rejects_a_historical_table_the_migration_never_mentions' => ['migration' => self::RELEASES_MIGRATION, 'tables' => ['video_data']],
            default => null,
        };
    }

    public function test_checks_isolated_tables_before_they_are_unlinked(): void
    {
        DB::statement('CREATE TABLE video_data (id INTEGER PRIMARY KEY, releases_id INTEGER)');
        self::assertTrue(true);
    }

    public function test_checks_other_open_connections(): void
    {
        config(['database.connections.schema_probe' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::connection('schema_probe')->statement('CREATE TABLE video_data (releases_id INTEGER)');
        self::assertTrue(true);
    }

    public function test_reports_explicit_test_only_tables(): void
    {
        DB::statement('CREATE TABLE declared_probe (id INTEGER PRIMARY KEY)');
        self::assertTrue(true);
    }

    public function test_accepts_the_faithful_video_fixture(): void
    {
        DB::statement('CREATE TABLE video_data (releases_id INTEGER PRIMARY KEY)');
        self::assertTrue(true);
    }

    public function test_checks_tables_dropped_before_the_test_ends(): void
    {
        DB::statement('CREATE TABLE video_data (id INTEGER PRIMARY KEY, releases_id INTEGER)');
        DB::statement('DROP TABLE video_data');
        self::assertTrue(true);
    }

    public function test_checks_tables_renamed_before_the_test_ends(): void
    {
        DB::statement('CREATE TABLE video_data (releases_id INTEGER)');
        DB::statement('ALTER TABLE video_data RENAME TO renamed_probe');
        self::assertTrue(true);
    }

    public function test_skips_intermediate_tables_that_migrations_build_and_drop(): void
    {
        $this->migrate('2026_04_01_000000_create_service_statuses_table', '2026_04_01_000001_create_service_incidents_table', '2026_04_01_120000_service_incidents_many_services');
        self::assertTrue(true);
    }

    public function test_checks_hand_built_tables_that_a_migration_drops(): void
    {
        $this->migrate('2026_04_01_000000_create_service_statuses_table');
        DB::statement('CREATE TABLE service_incidents (id INTEGER PRIMARY KEY, title TEXT, service_status_id INTEGER REFERENCES service_statuses (id))');
        $this->migrate('2026_04_01_120000_service_incidents_many_services');
        self::assertTrue(true);
    }

    public function test_checks_migration_built_tables_the_test_reshapes(): void
    {
        $this->migrate('2026_04_01_000000_create_service_statuses_table', '2026_04_01_000001_create_service_incidents_table');
        DB::statement('ALTER TABLE service_incidents ADD COLUMN invented TEXT');
        $this->migrate('2026_04_01_120000_service_incidents_many_services');
        self::assertTrue(true);
    }

    public function test_checks_migration_built_tables_the_test_drops(): void
    {
        $this->migrate('2026_04_01_000000_create_service_statuses_table', '2026_04_01_000001_create_service_incidents_table');
        DB::statement('DROP TABLE service_incidents');
        self::assertTrue(true);
    }

    public function test_checks_tables_a_test_defined_migration_builds_and_drops(): void
    {
        foreach (['CREATE TABLE video_data (id INTEGER PRIMARY KEY, releases_id INTEGER)', 'DROP TABLE video_data'] as $statement) {
            (new class($statement) extends Migration
            {
                public function __construct(private readonly string $statement) {}

                public function up(): void
                {
                    DB::statement($this->statement);
                }
            })->up();
        }
        self::assertTrue(true);
    }

    public function test_checks_tables_built_before_a_skip(): void
    {
        self::markTestSkipped('The table built in setUp() is still checked.');
    }

    public function test_skips_key_rules_for_declared_historical_tables(): void
    {
        DB::statement('CREATE TABLE releases (id INTEGER, guid TEXT, nzb_password TEXT)');
        self::assertTrue(true);
    }

    public function test_rejects_a_historical_migration_that_does_not_exist(): void
    {
        DB::statement('CREATE TABLE releases (id INTEGER PRIMARY KEY)');
        self::assertTrue(true);
    }

    public function test_rejects_a_historical_table_that_is_never_built(): void
    {
        self::assertTrue(true);
    }

    public function test_rejects_a_historical_table_the_migration_never_mentions(): void
    {
        DB::statement('CREATE TABLE video_data (releases_id INTEGER PRIMARY KEY)');
        self::assertTrue(true);
    }

    private function migrate(string ...$migrations): void
    {
        foreach ($migrations as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
    }
}
