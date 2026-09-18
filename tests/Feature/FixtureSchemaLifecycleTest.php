<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class FixtureSchemaLifecycleTest extends TestCase
{
    /** @return array<string, array{string, int, string}> */
    public static function lifecycleCases(): array
    {
        return [
            'isolated teardown' => ['test_checks_isolated_tables_before_they_are_unlinked', 1, 'connection=sqlite table=video_data R1 id'],
            'second connection' => ['test_checks_other_open_connections', 1, 'connection=schema_probe table=video_data R2 (releases_id)'],
            'declared table' => ['test_reports_explicit_test_only_tables', 0, 'SCHEMA_DECLARED'],
            'faithful fixture' => ['test_accepts_the_faithful_video_fixture', 0, 'OK ('],
            'dropped table' => ['test_checks_tables_dropped_before_the_test_ends', 1, 'table=video_data R1 id (inspected before: DROP TABLE video_data)'],
            'renamed table' => ['test_checks_tables_renamed_before_the_test_ends', 1, 'table=video_data R2 (releases_id) (inspected before: ALTER TABLE video_data RENAME'],
            'migration-built table' => ['test_skips_intermediate_tables_that_migrations_build_and_drop', 0, 'OK ('],
            'hand-built table a migration drops' => ['test_checks_hand_built_tables_that_a_migration_drops', 1, 'table=service_incidents R1 service_status_id (inspected before: drop table "service_incidents")'],
            'migration-built table reshaped by the test' => ['test_checks_migration_built_tables_the_test_reshapes', 1, 'table=service_incidents R1 invented (inspected before: drop table "service_incidents")'],
            'migration-built table the test drops' => ['test_checks_migration_built_tables_the_test_drops', 1, 'table=service_incidents R1 service_status_id (inspected before: DROP TABLE service_incidents)'],
            'test-defined migration' => ['test_checks_tables_a_test_defined_migration_builds_and_drops', 1, 'table=video_data R1 id (inspected before: DROP TABLE video_data)'],
            'skipped test' => ['test_checks_tables_built_before_a_skip', 1, 'connection=sqlite table=video_data R1 id'],
            'historical tables' => ['test_skips_key_rules_for_declared_historical_tables', 0, 'SCHEMA_HISTORICAL Tests\\Fixtures\\SchemaGuardLifecycleFixture table=releases before=2026_08_13_001652_normalize_and_optimize_releases_table.php'],
            'historical missing migration' => ['test_rejects_a_historical_migration_that_does_not_exist', 1, 'names a migration file that does not exist: database/migrations/2026_01_01_000000_missing_migration.php'],
            'historical unbuilt table' => ['test_rejects_a_historical_table_that_is_never_built', 1, 'names releases, but the test never builds that production table'],
            'historical unmentioned table' => ['test_rejects_a_historical_table_the_migration_never_mentions', 1, 'names video_data, but 2026_08_13_001652_normalize_and_optimize_releases_table.php never mentions it'],
        ];
    }

    #[DataProvider('lifecycleCases')]
    public function test_the_shared_postcondition_enforces_live_fixtures(string $method, int $exitCode, string $diagnostic): void
    {
        $process = new Process([PHP_BINARY, 'vendor/bin/phpunit', '--colors=never', '--filter', $method, 'tests/Fixtures/SchemaGuardLifecycleFixture.php'], dirname(__DIR__, 2));
        $process->setTimeout(20);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();
        self::assertSame($exitCode, $process->getExitCode(), $output);
        self::assertStringContainsString($diagnostic, $output);
    }
}
