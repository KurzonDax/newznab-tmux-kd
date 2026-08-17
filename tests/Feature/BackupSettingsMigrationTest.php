<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\SettingsTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackupSettingsMigrationTest extends TestCase
{
    public function test_seeder_and_upgrade_migration_supply_safe_backup_defaults(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name', 25)->primary();
            $table->text('value')->nullable();
        });

        (new SettingsTableSeeder)->run();

        $this->assertSame('0', $this->setting('backup_enabled'));
        $this->assertSame('0', $this->setting('backup_full_dow'));
        $this->assertSame('02:00', $this->setting('backup_full_time'));
        $this->assertSame('02:00', $this->setting('backup_daily_time'));
        $this->assertSame(storage_path('app/backups'), $this->setting('backup_location'));
        $this->assertSame('4', $this->setting('backup_keep_fulls'));
        $this->assertSame('1', $this->setting('backup_pause_tmux'));
        $this->assertSame('1', $this->setting('backup_incl_working'));

        DB::table('settings')->where('name', 'backup_enabled')->update(['value' => '1']);
        DB::table('settings')->where('name', 'backup_full_time')->delete();
        $migration = require database_path('migrations/2026_08_17_171303_add_database_backup_settings.php');
        $migration->up();

        $this->assertSame('1', $this->setting('backup_enabled'));
        $this->assertSame('02:00', $this->setting('backup_full_time'));

        $migration->down();
        $this->assertNull($this->setting('backup_enabled'));
    }

    private function setting(string $name): ?string
    {
        $value = DB::table('settings')->where('name', $name)->value('value');

        return $value === null ? null : (string) $value;
    }
}
