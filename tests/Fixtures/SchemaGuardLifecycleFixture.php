<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class SchemaGuardLifecycleFixture extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    protected function fixtureOnlyTables(): array
    {
        return ['declared_probe'];
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
}
