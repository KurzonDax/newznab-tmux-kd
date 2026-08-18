<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class DatabaseTimezoneConfigurationTest extends TestCase
{
    private string|false $originalDatabaseTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDatabaseTimezone = getenv('DB_TIMEZONE');
    }

    protected function tearDown(): void
    {
        $this->setDatabaseTimezone($this->originalDatabaseTimezone === false ? null : $this->originalDatabaseTimezone);

        parent::tearDown();
    }

    public function test_explicit_database_timezone_is_used_for_mysql_and_mariadb(): void
    {
        $this->setDatabaseTimezone('+05:45');

        $database = require base_path('config/database.php');

        $this->assertSame('+05:45', $database['connections']['mysql']['timezone']);
        $this->assertSame('+05:45', $database['connections']['mariadb']['timezone']);
    }

    public function test_empty_database_timezone_derives_the_current_application_timezone_offset(): void
    {
        $this->setDatabaseTimezone(null);
        config(['app.timezone' => 'America/Chicago']);

        $database = require base_path('config/database.php');
        $expected = (new \DateTimeImmutable('now', new \DateTimeZone('America/Chicago')))->format('P');

        $this->assertSame($expected, $database['connections']['mysql']['timezone']);
        $this->assertSame($expected, $database['connections']['mariadb']['timezone']);
    }

    private function setDatabaseTimezone(?string $value): void
    {
        if ($value === null) {
            putenv('DB_TIMEZONE');
            unset($_ENV['DB_TIMEZONE'], $_SERVER['DB_TIMEZONE']);

            return;
        }

        putenv('DB_TIMEZONE='.$value);
        $_ENV['DB_TIMEZONE'] = $value;
        $_SERVER['DB_TIMEZONE'] = $value;
    }
}
