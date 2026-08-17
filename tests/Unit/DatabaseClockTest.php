<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DatabaseClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DatabaseClockTest extends TestCase
{
    /**
     * @param  list<int|string>  $expectedBindings
     */
    #[DataProvider('driverExpressions')]
    public function test_it_builds_a_relative_cutoff_for_each_supported_driver(
        string $driver,
        string $expectedSql,
        array $expectedBindings
    ): void {
        $this->travelTo(Carbon::parse('2026-08-17 12:00:00', 'UTC'));
        DB::shouldReceive('getDriverName')->once()->andReturn($driver);

        $cutoff = DatabaseClock::cutoff(now()->subHours(2));

        $this->assertSame($expectedSql, $cutoff['sql']);
        $this->assertSame($expectedBindings, $cutoff['bindings']);
    }

    public function test_it_rejects_an_unsupported_driver(): void
    {
        DB::shouldReceive('getDriverName')->once()->andReturn('sqlsrv');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unsupported database driver for database-clock cutoff.');

        DatabaseClock::cutoff(now());
    }

    /**
     * @return array<string, array{string, string, list<int|string>}>
     */
    public static function driverExpressions(): array
    {
        return [
            'MariaDB' => ['mariadb', 'DATE_ADD(NOW(), INTERVAL ? SECOND)', [-7200]],
            'MySQL' => ['mysql', 'DATE_ADD(NOW(), INTERVAL ? SECOND)', [-7200]],
            'PostgreSQL' => ['pgsql', "CURRENT_TIMESTAMP + (? * INTERVAL '1 second')", [-7200]],
            'SQLite' => ['sqlite', 'datetime(CURRENT_TIMESTAMP, ?)', ['-7200 seconds']],
        ];
    }
}
