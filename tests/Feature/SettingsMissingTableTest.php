<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * On a fresh install the settings table does not exist yet, while console boot
 * eagerly builds commands whose service constructors read settings. The model
 * must degrade to null instead of crashing (see NNTmux/newznab-tmux#1866).
 */
final class SettingsMissingTableTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function createsSettingsTable(): bool
    {
        return false;
    }

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

    public function test_setting_value_returns_null_when_settings_table_is_missing(): void
    {
        $this->assertNull(Settings::settingValue('categorizeforeign'));
    }

    public function test_setting_value_returns_converted_value_when_table_exists(): void
    {
        $pdo = new PDO('sqlite:'.$this->isolatedDatabasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES ('delaytime', '42')");

        $this->assertSame(42, Settings::settingValue('delaytime'));
    }

    public function test_setting_value_rethrows_unrelated_query_errors(): void
    {
        $pdo = new PDO('sqlite:'.$this->isolatedDatabasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES ('delaytime', '5')");

        // A locked database must not be masked as a missing table.
        // Keep the default 60s sqlite busy timeout from slowing the suite down.
        DB::connection()->getPdo()->setAttribute(PDO::ATTR_TIMEOUT, 1);

        $locker = new PDO('sqlite:'.$this->isolatedDatabasePath);
        $locker->exec('BEGIN EXCLUSIVE TRANSACTION');
        $locker->exec("INSERT INTO settings (name, value) VALUES ('lockholder', '1')");

        try {
            $this->expectException(QueryException::class);

            Settings::settingValue('delaytime');
        } finally {
            $locker->exec('ROLLBACK');
        }
    }
}
