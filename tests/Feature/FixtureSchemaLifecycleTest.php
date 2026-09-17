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
        ];
    }

    #[DataProvider('lifecycleCases')]
    public function test_the_shared_postcondition_enforces_live_fixtures(string $method, int $exitCode, string $diagnostic): void
    {
        $process = new Process([PHP_BINARY, 'vendor/bin/phpunit', '--colors=never', '--filter', $method, 'tests/Fixtures/SchemaGuardLifecycleFixture.php'], dirname(__DIR__, 2), ['NNTMUX_SCHEMA_COLLECT' => false]);
        $process->setTimeout(20);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();
        self::assertSame($exitCode, $process->getExitCode(), $output);
        self::assertStringContainsString($diagnostic, $output);
    }
}
