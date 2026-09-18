<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Runs only inside ReconciliationMariaDbTest's subprocesses, against the isolated Sail MariaDB. */
final class SchemaGuardMariaDbFixture extends TestCase
{
    private string $database = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = 'schema_guard_'.bin2hex(random_bytes(6));
        $connection = ['driver' => 'mariadb', 'host' => 'mariadb', 'port' => 3306, 'database' => null,
            'username' => 'root', 'password' => 'password', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true];
        config(['database.connections.schema_guard_admin' => $connection,
            'database.connections.schema_guard' => [...$connection, 'database' => $this->database],
            'database.default' => 'schema_guard']);
        DB::connection('schema_guard_admin')->statement("CREATE DATABASE `{$this->database}`");
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('schema_guard_admin')->statement("DROP DATABASE IF EXISTS `{$this->database}`");
        } finally {
            parent::tearDown();
        }
    }

    public function test_checks_hand_built_tables(): void
    {
        $this->createLooseGroups();
        self::assertTrue(true);
    }

    public function test_checks_tables_dropped_before_the_test_ends(): void
    {
        $this->createLooseGroups();
        Schema::drop('usenet_groups');
        self::assertTrue(true);
    }

    public function test_checks_temporary_tables(): void
    {
        DB::statement('CREATE TEMPORARY TABLE video_data (id INT PRIMARY KEY, releases_id INT)');
        self::assertTrue(true);
    }

    public function test_accepts_the_schema_dump(): void
    {
        DB::unprepared((string) file_get_contents(database_path('schema/mariadb-schema.sql')));
        self::assertTrue(Schema::hasTable('releases'));
    }

    public function test_accepts_migrated_tables(): void
    {
        (require database_path('migrations/2026_09_08_121907_create_collection_reconciliation_tables.php'))->up();
        self::assertTrue(Schema::hasTable('reconciliation_claims'));
    }

    private function createLooseGroups(): void
    {
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });
    }
}
